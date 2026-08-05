<?php

namespace App\Policies;

use App\Models\Outbound;
use App\Models\User;

class OutboundPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Outbound $outbound): bool
    {
        return $user->getKey() === $outbound->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Outbound $outbound): bool
    {
        return false;
    }

    /**
     * Determine whether the user can replay the outbound.
     */
    public function replay(User $user, Outbound $outbound): bool
    {
        return $user->getKey() === $outbound->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Outbound $outbound): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Outbound $outbound): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Outbound $outbound): bool
    {
        return false;
    }
}
