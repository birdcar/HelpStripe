# 07 — Collision Detection

HelpSpot's signature affordance: open a request someone else is already
looking at and you see **"Sam is also viewing this request."** Two agents
stop replying over each other. This phase builds it the way the primitive
was designed for — **presence channels** over **Laravel Reverb** — and
throws in a live-updating timeline almost for free, because once the channel
exists, broadcasting one more event is trivial.

No polling. No `viewers` table. No heartbeat cron. The set of people
subscribed to a channel *is* the viewer list, and the websocket server keeps
it current as tabs open and close.

Files to read alongside this doc:

- `config/broadcasting.php`, `config/reverb.php` — driver + server config
- `routes/channels.php` — the `request.{id}` presence channel authorization
- `resources/js/echo.js`, `resources/js/app.js` — the client bootstrap
- `app/Events/NoteAdded.php` — now a broadcast event
- `app/Actions/Requests/AddNote.php` — the `broadcast(...)->toOthers()` site
- `resources/views/pages/requests/⚡show.blade.php` — the presence listeners
- `resources/views/pages/requests/viewers.blade.php` — the banner partial
- `tests/Feature/Collision/`

## 1. Why websockets, not polling

The naive version of "who's viewing this?" is a `viewers` table and a
JavaScript timer: every few seconds each open page POSTs "I'm still here,"
and reads back the list. It works, but it's a pile of moving parts — a
table, a write on an interval from every tab, a "last seen" column, a sweep
to expire stale rows, and a refresh lag equal to the poll interval.

A **websocket** flips the model. The browser opens one long-lived
connection and the server *pushes* changes the instant they happen. For
presence specifically, the server already knows exactly who's connected to a
given channel — membership is intrinsic to the connection, so the roster is
free and always accurate, and cleanup is automatic when a socket drops.

The cost is a server that speaks the websocket protocol. That's Reverb.

## 2. Reverb — the first-party websocket server

`php artisan install:broadcasting --reverb` installed
[Laravel Reverb](https://reverb.laravel.com/), a websocket server that
speaks the **Pusher protocol** (so the mature `pusher-js` client just
works) but runs on your own infrastructure — no third-party account, no
per-message billing. The installer:

- added `laravel/reverb` and published `config/reverb.php`
- set `BROADCAST_CONNECTION=reverb` and added the `REVERB_*` keys to `.env`
- wired `channels: routes/channels.php` into `bootstrap/app.php`
- scaffolded `resources/js/echo.js` and `import './echo'` in `app.js`

You start it with `php artisan reverb:start` — and that's now part of the
`composer run dev` stack (alongside `serve`, `queue:listen`, `pail`, and
`vite`), so a normal local session has the websocket server running without
a second thought.

The `REVERB_APP_ID` / `_KEY` / `_SECRET` identify *this app* to Reverb. The
matching `VITE_REVERB_*` keys are mirrored into the browser bundle (Vite
only exposes env vars prefixed `VITE_`) so Echo knows where to connect.

## 3. Three kinds of channel

Broadcasting has three channel types, in increasing privacy:

| Type         | Who can subscribe                       | Carries a member list? |
| ------------ | --------------------------------------- | ---------------------- |
| **Public**   | Anyone                                  | No                     |
| **Private**  | Authorized users (auth callback → bool) | No                     |
| **Presence** | Authorized users (auth callback → data) | **Yes**                |

We want **presence**, because the member list is the whole feature. A
private channel would tell us "you may listen here" but not "and here's
everyone else listening" — which is exactly the roster the banner needs.

## 4. The authorization handshake

Open `routes/channels.php`:

```php
Broadcast::channel('request.{helpdeskRequest}', function (User $user, Request $helpdeskRequest) {
    return $user->belongsToTeam($helpdeskRequest->team)
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});
```

A presence channel's auth callback doesn't return `true` — it returns an
**array of data about the user**, and that array becomes the user's entry in
everyone else's roster. Return `false` and the user is denied (and never
appears in anyone's list). The `{helpdeskRequest}` segment route-model-binds
to `App\Models\Request`, same as the HTTP detail page.

**The handshake, end to end:**

1. The browser asks Echo to join `presence-request.42`.
2. Echo POSTs to **`/broadcasting/auth`** with the channel name and its
   socket id.
3. Laravel runs the closure above. Authorized → it signs a token and returns
   the user's `channel_data`. Denied → 403.
4. With the signed token, the browser subscribes; Reverb adds it to the
   channel's member set and tells every other member "this person joined."

Watch it happen: open the request detail page with devtools → Network, and
you'll see the `POST /broadcasting/auth` fire, then a `wss://` connection
upgrade.

### Policy vs. channel authorization — the same question, twice

Notice the closure asks the *exact* question `RequestPolicy::view()` asks:
`$user->belongsToTeam($request->team)`. That's deliberate. The **same**
"can this user see this request?" rule is enforced in two places for two
transports — a **Policy** for the HTTP page, a **channel closure** for the
websocket. Keep them in sync: if you tighten the policy later, tighten the
channel too, or someone forbidden from the page could still see its presence.
(`tests/Feature/Collision/ChannelAuthTest.php` pins both paths.)

## 5. Listening from Livewire

`⚡show.blade.php` joins the channel and reacts to its events. Because the
channel name embeds the request id, the listeners are built at runtime in
`getListeners()` rather than with the static `#[On('echo-presence:…')]`
attribute:

```php
public function getListeners(): array
{
    $channel = 'echo-presence:request.'.$this->helpdeskRequest->id;

    return [
        "{$channel},here"      => 'syncViewers',   // initial roster
        "{$channel},joining"   => 'viewerJoined',  // someone arrived
        "{$channel},leaving"   => 'viewerLeft',    // someone left
        "{$channel},NoteAdded" => 'refreshTimeline',
    ];
}
```

`here` fires once on join with the full member array; `joining` and
`leaving` fire as the roster changes. The handlers keep a `$viewers` map
keyed by user id, **excluding the current user** (you never collide with
yourself) and **deduping by id** (the same person in two tabs counts once).
That map is pure client-driven state — no database writes. Presence is
ephemeral; it should live exactly as long as the connection.

`viewers.blade.php` is a presentational partial that renders that map as a
`flux:avatar.group` plus the warning-toned "X is also viewing this request."
It joins no channel of its own — a second subscription would double-count the
roster — so it's a plain partial, not a `⚡` component.

## 6. Live timeline — one more event, almost free

The channel is already there, so the live-reply demo costs one interface and
one payload method. `NoteAdded` now `implements ShouldBroadcast`:

```php
public function broadcastOn(): PresenceChannel
{
    return new PresenceChannel('request.'.$this->note->request_id);
}

public function broadcastWith(): array
{
    return ['note_id' => $this->note->id];  // the id ONLY — never the body
}
```

When another agent replies, the open detail page receives `NoteAdded`,
`refreshTimeline()` forgets the cached `notes` computed property, and the
next render re-queries the timeline — the new note simply appears.

**The payload is the note id and nothing else, on purpose.** A private note's
body must never ride the websocket to a browser. The client receives only
"note N changed" and re-fetches through the authorized component, which
applies the same visibility rules the page already enforces. This is the
named guard in `tests/Feature/Collision/BroadcastTest.php`: the payload-shape
assertion fails the moment someone fattens `broadcastWith` to include the
body.

### `toOthers()` — don't refresh your own page

In `AddNote` the dispatch is:

```php
broadcast(new NoteAdded($note))->toOthers();
```

The `broadcast()` helper still fires the event's listeners (the queued
`SendPublicReplyEmail` from Phase 3 runs as before) **and** broadcasts over
the websocket. `toOthers()` excludes the connection that triggered the
request — the author's own page already re-rendered its timeline from the
action's response, so it must not *also* refresh from the broadcast (a double
render). Echo attaches an `X-Socket-ID` header to outgoing requests;
`toOthers()` reads it. In non-browser contexts (a queued inbound-email reply,
the API channel) there's no socket id, so `toOthers()` harmlessly broadcasts
to everyone — exactly right when no connection "owns" the action.

## 7. Demo script: two browsers

You need Reverb running and a built front end:

```bash
composer run dev          # serve + queue + reverb + vite, all at once
# a la carte, you'd run each in its own pane: php artisan serve,
# php artisan queue:listen, php artisan reverb:start, and bun run dev
```

Log in as two different seeded staff (e.g. a normal window + an incognito
window, or two browsers):

1. **Open the same request** in both. Within ~1s, each banner shows the
   other person: "Riley is also viewing this request." The avatar stack fills
   in.
2. **Close one tab.** The banner in the other window clears within a beat —
   no stale "still here" ghost, because the dropped socket leaves the
   channel.
3. **Reply (public) in window A.** Window B's timeline grows the new note
   without a reload. Window A does *not* double-render it — `toOthers()`.
4. **Add a private note in A.** B's timeline updates too (both are staff on
   the same channel) — but only the *id* crossed the wire; B re-queried the
   body it's authorized to see. The customer portal has no such channel and
   no such note, so nothing leaks there.

### Graceful degradation

Stop Reverb (Ctrl-C its pane) and reload the request page. **It still
works** — you can read the timeline, reply, change properties, everything.
Echo simply fails to connect (a console warning, nothing more), so the banner
never appears and the timeline won't live-update. Collision detection is an
enhancement layered on top, never a hard dependency — there is no server-side
code path that blocks on the websocket.

## 8. Out of scope / Future Considerations

- **Ghost viewers on a hard crash.** If a browser dies without closing the
  socket cleanly, the Pusher-protocol presence timeout (~30s) reaps it. That
  brief window of a stale viewer is cosmetic and documented, not engineered
  around.
- **"X is typing…"** indicators, read receipts, cursor positions — all build
  on the same presence channel, but they're product polish beyond the
  teaching goal.
- **Scaling Reverb** (multiple nodes, a Redis pub/sub backplane) is a
  deployment concern; one Reverb process is plenty for the demo.

## 9. Verify

```bash
php artisan test --compact --filter=Collision   # channel auth + broadcast contract + viewer state
bun run build                                    # Echo bundled into the manifest
./init.sh                                        # lint + static analysis + full suite
```

The Pest suite proves the auth matrix, the broadcast channel/payload shape,
and the viewer-state rules without a websocket server. The two-browser walk
in §7 is the truth test for the live behavior — presence is inherently
multi-client, so that's the check that actually matters.
