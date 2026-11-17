<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MaintenanceRequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = MaintenanceRequest::with(['lease.room:id,room_number,title', 'tenant:id,name', 'landlord:id,name'])->latest();
        if ($user->isTenant()) $query->where('tenant_id', $user->id);
        elseif ($user->isLandlord()) $query->where('landlord_id', $user->id);
        elseif (! $user->isAdmin()) abort(403);

        return $query->paginate(30)->through(fn (MaintenanceRequest $item) => $this->serialize($item));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isTenant(), 403);
        $data = $request->validate([
            'lease_id' => ['required', 'integer', 'exists:leases,id'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        $lease = Lease::with('room')->whereKey($data['lease_id'])->where('tenant_id', $request->user()->id)->where('status', 'active')->firstOrFail();
        $path = $request->file('photo')?->store('maintenance/'.$lease->id, 'public');
        if ($request->hasFile('photo') && ! $path) {
            return response()->json(['message' => 'The maintenance photo could not be saved. Please try again.'], 500);
        }
        try {
            $item = MaintenanceRequest::create([
                'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'landlord_id' => $lease->landlord_id,
                'title' => $data['title'], 'description' => $data['description'], 'photo_path' => $path,
            ]);
        } catch (\Throwable $exception) {
            if ($path) Storage::disk('public')->delete($path);
            throw $exception;
        }
        Notification::create(['user_id' => $lease->landlord_id, 'type' => 'maintenance', 'title' => 'New maintenance request', 'body' => 'A tenant reported: '.$item->title, 'data' => ['maintenance_request_id' => $item->id]]);
        Notification::create(['user_id' => $lease->tenant_id, 'type' => 'maintenance', 'title' => 'Maintenance request submitted', 'body' => 'Your request “'.$item->title.'” was sent to your landlord.', 'data' => ['maintenance_request_id' => $item->id]]);

        return response()->json($this->serialize($item->load(['lease.room:id,room_number,title', 'tenant:id,name', 'landlord:id,name'])), 201);
    }

    public function update(Request $request, MaintenanceRequest $maintenanceRequest)
    {
        if ($request->user()->isTenant()) {
            abort_unless($maintenanceRequest->tenant_id === $request->user()->id, 404);

            $data = $request->validate([
                'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
                'remove_photo' => ['sometimes', 'boolean'],
            ]);
            $removePhoto = (bool) ($data['remove_photo'] ?? false);

            if ($request->hasFile('photo') && $removePhoto) {
                throw ValidationException::withMessages(['photo' => 'Choose a replacement photo or remove the saved photo, but not both.']);
            }
            if (! $request->hasFile('photo') && ! $removePhoto) {
                throw ValidationException::withMessages(['photo' => 'Choose a photo to replace the current one, or select Remove photo.']);
            }

            $newPath = $request->file('photo')?->store('maintenance/'.$maintenanceRequest->lease_id, 'public');
            if ($request->hasFile('photo') && ! $newPath) {
                return response()->json(['message' => 'The maintenance photo could not be saved. Please try again.'], 500);
            }

            try {
                $oldPath = DB::transaction(function () use ($maintenanceRequest, $request, $newPath, $removePhoto) {
                    $lockedRequest = MaintenanceRequest::query()->whereKey($maintenanceRequest->id)->lockForUpdate()->firstOrFail();
                    abort_unless($lockedRequest->tenant_id === $request->user()->id, 404);
                    if ($lockedRequest->status !== 'pending') {
                        throw ValidationException::withMessages(['photo' => 'Photos can only be changed while a maintenance request is pending.']);
                    }

                    $oldPath = $lockedRequest->photo_path;
                    $lockedRequest->update(['photo_path' => $removePhoto ? null : $newPath]);

                    return $oldPath;
                });
            } catch (\Throwable $exception) {
                if ($newPath) Storage::disk('public')->delete($newPath);
                throw $exception;
            }

            if ($oldPath && $oldPath !== $newPath) Storage::disk('public')->delete($oldPath);

            return response()->json($this->serialize($maintenanceRequest->fresh(['lease.room:id,room_number,title', 'tenant:id,name', 'landlord:id,name'])));
        }

        abort_unless($request->user()->isAdmin() || ($request->user()->isLandlord() && $maintenanceRequest->landlord_id === $request->user()->id), 404);
        $data = $request->validate(['status' => ['required', 'in:pending,in_progress,resolved']]);
        $maintenanceRequest->update(['status' => $data['status'], 'resolved_at' => $data['status'] === 'resolved' ? now() : null]);
        $statusLabel = str_replace('_', ' ', $data['status']);
        foreach (array_unique([$maintenanceRequest->tenant_id, $maintenanceRequest->landlord_id]) as $recipientId) {
            Notification::create(['user_id' => $recipientId, 'type' => 'maintenance', 'title' => 'Maintenance request updated', 'body' => $maintenanceRequest->title.' is now '.$statusLabel.'.', 'data' => ['maintenance_request_id' => $maintenanceRequest->id]]);
        }

        return response()->json($this->serialize($maintenanceRequest->fresh(['lease.room:id,room_number,title', 'tenant:id,name', 'landlord:id,name'])));
    }

    private function serialize(MaintenanceRequest $item): array
    {
        return [...$item->toArray(), 'photo_url' => $item->photo_path ? Storage::disk('public')->url($item->photo_path) : null];
    }
}
