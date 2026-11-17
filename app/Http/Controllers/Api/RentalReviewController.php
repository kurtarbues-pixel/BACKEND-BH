<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\RentalReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;

class RentalReviewController extends Controller
{
    public function forRoom(Request $request, int $room)
    {
        return RentalReview::with('tenant:id,name')->where('room_id', $room)->where('status', 'approved')->latest()->paginate(20);
    }

    public function mine(Request $request)
    {
        abort_unless($request->user()->isTenant(), 403);
        $leases = Lease::with(['room:id,room_number,title', 'inspections'])->where('tenant_id', $request->user()->id)->latest()->get();
        $existing = RentalReview::where('tenant_id', $request->user()->id)->get()->keyBy('lease_id');
        return response()->json(['data' => $leases->map(fn ($lease) => [
            'lease' => $lease, 'review' => $existing->get($lease->id),
        ])]);
    }

    public function store(Request $request, Lease $lease)
    {
        abort_unless($request->user()->isTenant() && $lease->tenant_id === $request->user()->id, 404);
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'review' => ['required', 'string', 'min:10', 'max:3000']]);
        $review = DB::transaction(function () use ($lease, $request, $data) {
            $locked = Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'ended') {
                throw ValidationException::withMessages(['lease' => 'You can review a room after your lease has ended.']);
            }
            if ($locked->moveOutInspection()->where('status', 'completed')->doesntExist()) {
                throw ValidationException::withMessages(['lease' => 'A completed move-out inspection is required before reviewing this rental.']);
            }
            if ($locked->review()->exists()) {
                throw ValidationException::withMessages(['lease' => 'You have already reviewed this rental.']);
            }

            return RentalReview::create([
                'lease_id' => $locked->id,
                'room_id' => $locked->room_id,
                'tenant_id' => $request->user()->id,
                ...$data,
            ]);
        });

        return response()->json($review, 201);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        return RentalReview::with(['tenant:id,name', 'room:id,room_number,title'])->latest()->paginate(30);
    }

    public function moderate(Request $request, RentalReview $review)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['status' => ['required', 'in:approved,rejected']]);
        $review->update(['status' => $data['status'], 'moderated_by' => $request->user()->id, 'moderated_at' => now()]);
        return response()->json($review->fresh(['tenant:id,name', 'room:id,room_number,title']));
    }
}
