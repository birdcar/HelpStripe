<?php

use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Channel authorization closures — the websocket equivalent of route
 * middleware + policies. When the browser asks Echo to join a private or
 * presence channel, it first POSTs to /broadcasting/auth; the matching
 * closure below decides whether to issue the signed token that lets the
 * connection subscribe. No closure = no subscription.
 */

/**
 * The default user channel from the starter kit: lets a user listen on
 * their own private channel (notifications, etc.). Left in place.
 */
Broadcast::channel('App.Models.User.{id}', function (User $user, string $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Collision detection — `request.{id}` is a PRESENCE channel, so its
 * membership list *is* the feature: everyone subscribed is, by definition,
 * looking at this request right now. That's why the closure returns an
 * array of viewer data (id + name) instead of a bare `true` — presence
 * channels surface that payload to every other member as the live roster
 * the "Riley is also viewing this request" banner renders from. Return
 * `false`/`null` and the user is denied the channel (and never appears in
 * anyone's roster).
 *
 * `{helpdeskRequest}` route-model-binds to App\Models\Request — the same
 * binding the HTTP detail page uses. A non-existent id resolves to null and
 * the join is denied; the closure never sees a missing model.
 *
 * The authorization rule is identical to RequestPolicy::view() —
 * team membership. That is the deliberate teaching contrast in the tour
 * doc: the *same* "can this user see this request?" question is answered by
 * a Policy for an HTTP request and by this closure for a websocket
 * subscription. Keep the two in sync — see docs/tour/07-collision-detection.md.
 *
 * @return array{id: int, name: string}|false
 */
Broadcast::channel('request.{helpdeskRequest}', function (User $user, Request $helpdeskRequest) {
    return $user->belongsToTeam($helpdeskRequest->team)
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});
