<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification as UserNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->notifications()->latest()->paginate(30);
    }

    public function markAsRead(Request $request, UserNotification $notification)
    {
        $ownedNotification = $request->user()->notifications()
            ->whereKey($notification->getKey())
            ->firstOrFail();
        $ownedNotification->update(['read_at' => now()]);

        return response()->json($ownedNotification->fresh());
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'All marked as read']);
    }
}
