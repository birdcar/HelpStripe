<?php

namespace App\Policies;

use App\Models\Request;
use App\Models\User;

/**
 * Team-membership gate for helpdesk requests.
 *
 * Authorization here is deliberately coarse: if you belong to the
 * installation's team you can work its requests. Finer distinctions
 * (Administrator vs Help Desk Staff) live in spatie permissions and gate
 * admin-only screens, not individual tickets.
 *
 * Laravel discovers this policy automatically from the naming convention
 * (App\Models\Request → App\Policies\RequestPolicy) — no registration.
 */
class RequestPolicy
{
    /**
     * Determine whether the user can view the request queue.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the request.
     */
    public function view(User $user, Request $request): bool
    {
        return $user->belongsToTeam($request->team);
    }

    /**
     * Determine whether the user can create requests.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the request — reply, note,
     * assign, recategorize, or change its status.
     */
    public function update(User $user, Request $request): bool
    {
        return $user->belongsToTeam($request->team);
    }

    /**
     * Determine whether the user can delete the request.
     */
    public function delete(User $user, Request $request): bool
    {
        return $user->belongsToTeam($request->team);
    }
}
