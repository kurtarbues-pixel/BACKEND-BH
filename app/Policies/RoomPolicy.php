<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

class RoomPolicy
{
    public function view(User $user, Room $room): bool
    {
        return $user->isAdmin()
            || ($user->isLandlord() && $room->landlord_id === $user->id)
            || ($user->isTenant() && in_array($room->status, ['available', 'reserved'], true));
    }

    public function create(User $user): bool
    {
        return $user->isLandlord() || $user->isAdmin();
    }

    public function update(User $user, Room $room): bool
    {
        return $user->isLandlord() && $room->landlord_id === $user->id;
    }

    public function delete(User $user, Room $room): bool
    {
        return $user->isAdmin() || $this->update($user, $room);
    }
}
