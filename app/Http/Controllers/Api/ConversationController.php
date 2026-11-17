<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationMessage;
use App\Models\Lease;
use App\Models\LeaseConversation;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isTenant() || $user->isLandlord(), 403);
        $field = $user->isTenant() ? 'tenant_id' : 'landlord_id';
        $items = LeaseConversation::with(['lease.room:id,room_number,title', 'tenant:id,name', 'landlord:id,name'])
            ->withCount(['messages as unread_count' => fn ($query) => $query->whereNull('read_at')->where('sender_id', '!=', $user->id)])
            ->where($field, $user->id)->withMax('messages', 'created_at')->latest('messages_max_created_at')->get();

        return response()->json(['data' => $items]);
    }

    public function start(Request $request, Lease $lease)
    {
        $user = $request->user();
        abort_unless(($user->isTenant() && $lease->tenant_id === $user->id) || ($user->isLandlord() && $lease->landlord_id === $user->id), 404);
        abort_unless(in_array($lease->status, ['active', 'ended'], true), 404);
        $conversation = LeaseConversation::firstOrCreate(
            ['lease_id' => $lease->id], ['tenant_id' => $lease->tenant_id, 'landlord_id' => $lease->landlord_id]
        );

        return response()->json($this->details($conversation->id, $user->id));
    }

    public function show(Request $request, LeaseConversation $conversation)
    {
        $this->authorizeParticipant($request, $conversation);
        return response()->json($this->details($conversation->id, $request->user()->id));
    }

    public function send(Request $request, LeaseConversation $conversation)
    {
        $this->authorizeParticipant($request, $conversation);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $message = $conversation->messages()->create(['sender_id' => $request->user()->id, 'body' => trim($data['body'])]);
        $recipient = $request->user()->id === $conversation->tenant_id ? $conversation->landlord_id : $conversation->tenant_id;
        \App\Models\Notification::create(['user_id' => $recipient, 'type' => 'message', 'title' => 'New message', 'body' => $request->user()->name.' sent you a message.', 'data' => ['conversation_id' => $conversation->id]]);

        return response()->json($message->load('sender:id,name'), 201);
    }

    private function details(int $id, int $userId): array
    {
        $conversation = LeaseConversation::with(['lease.room:id,room_number,title', 'tenant:id,name', 'landlord:id,name'])->findOrFail($id);
        $conversation->messages()->whereNull('read_at')->where('sender_id', '!=', $userId)->update(['read_at' => now()]);
        $messages = $conversation->messages()->with('sender:id,name')->oldest()->limit(200)->get();

        return ['conversation' => $conversation, 'messages' => $messages];
    }

    private function authorizeParticipant(Request $request, LeaseConversation $conversation): void
    {
        $user = $request->user();
        abort_unless(($user->isTenant() && $conversation->tenant_id === $user->id) || ($user->isLandlord() && $conversation->landlord_id === $user->id), 404);
    }
}
