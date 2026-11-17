<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoomSearchRequest;
use App\Http\Requests\StoreRoomImagesRequest;
use App\Http\Requests\StoreRoomAvailabilityBlockRequest;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\ToggleAvailabilityRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Models\RoomImage;
use App\Models\RoomAvailabilityBlock;
use App\Models\Inquiry;
use App\Services\ActivityLogger;
use App\Services\RoomAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RoomController extends Controller
{
    public function index(RoomSearchRequest $request)
    {
        $user = $request->user();
        $query = Room::query()->with(['images', 'landlord.role'])->filter($request->validated());

        if ($user?->isLandlord()) {
            $query->where('landlord_id', $user->id);
        } elseif (! $user?->isAdmin()) {
            $query->where('status', 'available');
        }

        $rooms = $query->latest()->paginate($request->integer('per_page', 9));

        return RoomResource::collection($rooms);
    }

    public function show(Room $room)
    {
        $this->authorize('view', $room);
        $room->load(['images', 'landlord.role']);

        return new RoomResource($room);
    }

    public function store(StoreRoomRequest $request)
    {
        abort_unless($request->user()->isLandlord(), 403, 'Only landlords can create listings.');
        $data = $request->validated();
        $data['landlord_id'] = $request->user()->id;
        $data['status'] = $data['status'] ?? 'available';
        $data['amenities'] = $data['amenities'] ?? [];

        $exists = Room::where('landlord_id', $request->user()->id)
            ->where('room_number', $data['room_number'])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'room_number' => 'You already have a room with this number.',
            ]);
        }

        $room = Room::create($data);
        ActivityLogger::log('room.created', $room);

        return (new RoomResource($room->load(['images', 'landlord.role'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateRoomRequest $request, Room $room)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->can('update', $room), 403);
        $room->update($request->validated());
        ActivityLogger::log('room.updated', $room);

        return new RoomResource($room->fresh(['images', 'landlord.role']));
    }

    public function destroy(Request $request, Room $room)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->can('delete', $room), 403);

        if ($room->leases()->exists()) {
            throw ValidationException::withMessages([
                'room' => 'Cannot delete a room with lease history. Keep the room record for billing and audit history.',
            ]);
        }

        $room->delete();
        ActivityLogger::log('room.deleted', $room);

        return response()->json(['message' => 'Room deleted.']);
    }

    public function toggleAvailability(ToggleAvailabilityRequest $request, Room $room)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->can('update', $room), 403);
        $room = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $room) {
            $lockedRoom = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            if ($lockedRoom->activeLease()->exists() || ($lockedRoom->status === 'reserved' && $request->validated('status') === 'maintenance')) {
                throw ValidationException::withMessages([
                    'status' => 'Resolve the current lease or offer before changing room availability.',
                ]);
            }
            if ($request->validated('status') === 'available') {
                Inquiry::where('room_id', $lockedRoom->id)
                    ->where('status', Inquiry::APPROVED)
                    ->where('offer_expires_at', '>', now())
                    ->update(['status' => Inquiry::REJECTED, 'decision_reason' => 'The room offer was cancelled by its landlord.']);
            }
            $lockedRoom->update(['status' => $request->validated('status')]);
            ActivityLogger::log('room.availability', $lockedRoom, ['status' => $lockedRoom->status]);

            return $lockedRoom;
        });

        return new RoomResource($room->fresh(['images', 'landlord.role']));
    }

    public function uploadImages(StoreRoomImagesRequest $request, Room $room)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->can('update', $room), 403);

        $storedPaths = [];
        $previousImages = $request->boolean('replace') ? $room->images()->get() : collect();
        try {
            $sort = $request->boolean('replace') ? 0 : (int) $room->images()->max('sort_order');
            foreach ($request->file('images') as $file) {
                $path = $file->store('rooms/'.$room->id, 'public');
                if (! $path) {
                    throw new \RuntimeException('The server could not save one of the selected room photos.');
                }
                $storedPaths[] = $path;
            }

            \Illuminate\Support\Facades\DB::transaction(function () use ($request, $room, $storedPaths, $sort) {
                if ($request->boolean('replace')) {
                    $room->images()->delete();
                }
                foreach ($request->file('images') as $index => $file) {
                    RoomImage::create([
                        'room_id' => $room->id,
                        'path' => $storedPaths[$index],
                        'original_name' => $file->getClientOriginalName(),
                        'sort_order' => $sort + $index + 1,
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $exception;
        }

        if ($request->boolean('replace')) {
            foreach ($previousImages as $oldImage) {
                Storage::disk('public')->delete($oldImage->path);
            }
        }

        ActivityLogger::log('room.images', $room);

        return (new RoomResource($room->fresh(['images', 'landlord.role'])))
            ->response()
            ->setStatusCode(200);
    }

    public function destroyImage(Request $request, Room $room, RoomImage $image)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->can('update', $room), 403);

        if ($image->room_id !== $room->id) {
            abort(404);
        }

        Storage::disk('public')->delete($image->path);
        $image->delete();

        ActivityLogger::log('room.images.deleted', $room, ['image_id' => $image->id]);

        return response()->json([
            'message' => 'Image removed.',
            'room' => new RoomResource($room->fresh(['images', 'landlord.role'])),
        ]);
    }

    public function checkAvailability(Request $request, Room $room)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->isLandlord() || $room->status === 'available', 404);
        $hasActive = \App\Models\Lease::where('active_room_id', $room->id)->exists();
        $isAvailable = ($room->status === 'available') && ! $hasActive;

        return response()->json([
            'room_id' => $room->id,
            'room_number' => $room->room_number,
            'status' => $room->status,
            'is_available' => $isAvailable,
        ]);
    }

    public function availabilityCalendar(Request $request, Room $room, RoomAvailabilityService $availability)
    {
        $this->authorize('view', $room);
        $dates = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);
        if (now()->parse($dates['start_date'])->diffInDays(now()->parse($dates['end_date'])) > 62) {
            throw ValidationException::withMessages(['end_date' => 'Choose a calendar range of 63 days or less.']);
        }

        return response()->json([
            'room_id' => $room->id,
            'start_date' => $dates['start_date'],
            'end_date' => $dates['end_date'],
            'days' => $availability->calendar($room, $dates['start_date'], $dates['end_date']),
            'blocks' => $request->user()->isAdmin() || $request->user()->isLandlord()
                ? $room->availabilityBlocks()->whereDate('start_date', '<=', $dates['end_date'])->whereDate('end_date', '>=', $dates['start_date'])->get()
                : [],
        ]);
    }

    public function storeAvailabilityBlock(StoreRoomAvailabilityBlockRequest $request, Room $room, RoomAvailabilityService $availability)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $room, $availability) {
            $lockedRoom = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            $data = $request->validated();
            $availability->assertAvailable($lockedRoom, $data['start_date'], $data['end_date']);
            $block = RoomAvailabilityBlock::create([
                'room_id' => $lockedRoom->id,
                'created_by' => $request->user()->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'reason' => $data['reason'] ?? null,
            ]);
            ActivityLogger::log('room.availability_blocked', $block, ['room_id' => $lockedRoom->id]);

            return response()->json(['block' => $block], 201);
        });
    }

    public function destroyAvailabilityBlock(Request $request, Room $room, RoomAvailabilityBlock $block)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->can('update', $room), 403);
        abort_unless($block->room_id === $room->id, 404);

        $block->delete();
        ActivityLogger::log('room.availability_unblocked', $block, ['room_id' => $room->id]);

        return response()->json(['message' => 'Room availability dates are open again.']);
    }
}

