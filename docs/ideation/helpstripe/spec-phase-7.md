# Implementation Spec: HelpStripe - Phase 7: Collision Detection

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

HelpSpot's collision detection — "Sam is also viewing this request" — implemented with the primitive built for exactly this: **presence channels** over Laravel Reverb. Each request detail page joins presence channel `request.{id}`; channel membership *is* the viewer list, with join/leave handled by Echo automatically. No polling, no manual heartbeat bookkeeping.

Two deliverables: (1) the presence banner on the request detail page (avatars + names of other current viewers, live-updating); (2) live timeline updates — `NoteAdded` gains `ShouldBroadcast` (on the same channel, `broadcastWhen` public-or-staff visibility) so an open detail page refreshes its timeline when someone else replies. The second is small once the channel exists and makes the collision demo dramatically better: two browsers, one types, the other sees it.

Stack: `composer require laravel/reverb` (`install:broadcasting` flow), `laravel-echo` + `pusher-js` via bun, channel auth in `routes/channels.php` gated on team membership, Alpine + Livewire `#[On('echo-presence:...')]` handlers in the detail SFC. Runs locally on Herd with `php artisan reverb:start` added to the `composer run dev` concurrently stack.

## Feedback Strategy

**Inner-loop command**: `php artisan test --compact --filter=Collision`

**Playground**: Two browser windows (normal + incognito, two seeded staff logins) on the same seeded request, with `reverb:start` running; Pest tests for channel auth + broadcast payloads.

**Why this approach**: Presence is inherently multi-client — the two-browser check is the truth test; auth/broadcast logic is covered fast in Pest.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `routes/channels.php` | `request.{id}` presence channel authorization (team membership; returns id/name/avatar payload) |
| `resources/js/echo.js` | Echo bootstrap (Reverb broadcaster) |
| `resources/views/pages/requests/⚡viewers.blade.php` | Presence banner SFC/partial: avatar stack + "also viewing" text |
| `tests/Feature/Collision/ChannelAuthTest.php` | Auth endpoint: member allowed w/ payload, non-member denied, guest denied |
| `tests/Feature/Collision/BroadcastTest.php` | NoteAdded broadcasts on right channel with right payload; private-note visibility rule |
| `docs/tour/07-collision-detection.md` | Tour doc: broadcasting concepts, Reverb, Echo, presence channels + demo script |

### Modified Files

| File Path | Changes |
| --- | --- |
| `composer.json` | `laravel/reverb` (via `php artisan install:broadcasting --reverb --no-interaction`, which also wires config/broadcasting.php, .env keys) |
| `package.json` | `laravel-echo`, `pusher-js` (bun add) |
| `resources/js/app.js` | import `./echo` |
| `.env.example` | `REVERB_*` + `VITE_REVERB_*` keys |
| `app/Events/NoteAdded.php` | implement `ShouldBroadcast`, `broadcastOn` presence channel, `broadcastWith` trimmed payload, `broadcastWhen` |
| `resources/views/pages/requests/⚡show.blade.php` | mount viewers component; `#[On('echo-presence:request.{id},here|joining|leaving')]`-driven viewer state; `#[On('echo-presence:...,NoteAdded')]` timeline refresh |
| `composer.json` `dev` script | add `php artisan reverb:start` to concurrently stack |

## Implementation Details

### Broadcasting install + Echo bootstrap

**Pattern to follow**: Laravel broadcasting + Reverb docs (Boost `search-docs` `['reverb installation', 'presence channels', 'echo livewire']`) — verify the Livewire 4 echo-listener attribute syntax in the Livewire docs before wiring.

**Overview**: `php artisan install:broadcasting --reverb` scaffolds config + env; Echo bootstrap with the Reverb connection from `VITE_REVERB_*`; `bun run build` for the manifest.

No feedback loop — install/config; verified by the channel auth test + a `wss` connection visible in browser devtools.

### Presence channel authorization

**Overview**:

```php
// routes/channels.php
Broadcast::channel('request.{helpdeskRequest}', function (User $user, Request $helpdeskRequest) {
    return $user->belongsToTeam($helpdeskRequest->team)
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});
```

**Key decisions**:
- Presence (not private) channel — the member-list payload is the feature.
- Auth mirrors `RequestPolicy` logic; the tour doc contrasts HTTP authorization (policy) with channel authorization (closure) for the same resource.

**Feedback loop**:
- **Playground**: `ChannelAuthTest` posting to `/broadcasting/auth`.
- **Experiment**: team member → 200 with channel_data containing name; user from another team → 403; guest → 403.
- **Check command**: `php artisan test --compact --filter=ChannelAuthTest`

### Viewer banner + live timeline (⚡show changes)

**Pattern to follow**: existing ⚡show SFC; Livewire `#[On('echo-presence:…')]` listeners

**Overview**: The detail component keeps a `$viewers` array updated by `here` (initial list), `joining`, `leaving` presence events; the banner renders other-than-me viewers as an avatar stack with a subtle warning tone ("Riley is also viewing this request") — the HelpSpot collision affordance. `NoteAdded` echo events refresh the timeline (`$refresh` or targeted re-computed property).

**Key decisions**:
- Viewer state lives client-side via Livewire echo listeners (no DB writes for presence) — presence is ephemeral by design; tour doc contrasts with a polling/table approach.
- `broadcastWith` sends only `note_id`; the component re-queries — avoids trusting broadcast payloads for rendering and sidesteps private-note leakage through the websocket payload. `broadcastWhen` still suppresses nothing (note ids are harmless), but the private-note body never rides the wire — named decision.
- Echo events for one's own actions (`toOthers()`) excluded so the author doesn't double-refresh.

**Implementation steps**:
1. Banner partial with static fake data (style first).
2. Wire `here/joining/leaving` listeners → `$viewers`.
3. `NoteAdded` ShouldBroadcast + `toOthers` + timeline refresh listener.
4. Two-browser verification.

**Feedback loop**:
- **Playground**: two browsers on one request, `reverb:start` + queue running.
- **Experiment**: open second browser → banner appears in first within ~1s; close tab → disappears; reply in one → timeline updates in other; private note also updates (staff channel) but never appears on the portal (different surface, no channel there).
- **Check command**: `php artisan test --compact --filter=BroadcastTest` (payload shape; live behavior is the manual matrix)

### Tour doc 07

Covers: why websockets vs polling, Reverb as first-party server, channel types (public/private/presence), the auth handshake (trace the `/broadcasting/auth` request in devtools), Echo + Livewire integration, `toOthers`. Demo script: the two-browser collision + live-reply demo, plus "kill reverb:start and watch it degrade gracefully" (page still works, no banner).

## Testing Requirements

| Test File | Coverage |
| --- | --- |
| `ChannelAuthTest` | Member/non-member/guest auth matrix; channel_data payload shape |
| `BroadcastTest` | `NoteAdded` implements ShouldBroadcast; channel name; payload contains note_id only; Event::assertDispatched broadcast conditions |

**Key edge cases**: request deleted while channel open (auth closure 404s gracefully); user in two tabs counts once in the banner (dedupe by user id).

### Manual Testing

- [ ] Two-browser collision demo per tour doc
- [ ] Reverb absent → page functional, console warning only

## Error Handling

| Error Scenario | Handling Strategy |
| --- | --- |
| Reverb not running | Echo connection fails silently; UI degrades to no banner (no hard dependency) |
| Auth failure on channel | Echo error in console; banner absent — acceptable |
| Stale viewer on crash | Pusher-protocol presence timeout cleans up (~30s); named, not engineered around |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
| --- | --- | --- | --- | --- |
| Presence banner | Ghost viewers | abrupt disconnect | brief false collision warning | protocol timeout; cosmetic, documented |
| Broadcast payload | Sensitive data on wire | future dev fattens broadcastWith | note bodies via websocket | payload-shape test pins note_id-only |
| Vite/Echo | Missing manifest entry | forgot `bun run build` | ViteException | tour doc setup step; CLAUDE.md guidance already covers |
| Channel auth | Drift from RequestPolicy | policy changes, closure doesn't | viewer sees presence on forbidden request | tour doc note: keep in sync; test covers both paths |

## Validation Commands

```bash
composer lint
php artisan test --compact --filter=Collision
bun run build
composer test
./init.sh
```

## Rollout Considerations

`composer run dev` now needs the reverb process — verify the concurrently line stays readable. Update `feature_list.json` + `progress.md` with evidence on completion.

## Open Items

- [ ] Verify Livewire 4's current echo-presence attribute syntax (`#[On('echo-presence:...')]`) via Boost `search-docs` before wiring.
