<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request)
    {
        $rooms = $request->user()->favoriteRooms()
            ->where('status', 'available')
            ->with(['images', 'landlord.role'])
            ->latest('room_favorites.created_at')
            ->paginate(20);

        return RoomResource::collection($rooms);
    }

    public function store(Request $request, Room $room)
    {
        abort_unless($room->status === 'available', 404);
        $request->user()->favoriteRooms()->syncWithoutDetaching([$room->id]);
        ActivityLogger::log('room.favorited', $room);

        return response()->json(['message' => 'Room saved to favorites.']);
    }

    public function destroy(Request $request, Room $room)
    {
        $request->user()->favoriteRooms()->detach($room->id);
        ActivityLogger::log('room.unfavorited', $room);

        return response()->json(['message' => 'Room removed from favorites.']);
    }
}
