# 04 — Self-Service Portal

This phase adds the *customer-facing* half of HelpStripe: a public,
unauthenticated portal where someone can submit a request, get a
confirmation email with an access key, and come back to check status and
reply — all without ever creating an account.

That last point is the whole shape of the feature. HelpSpot (and HelpStripe)
keep the public out of the `users` table entirely: a **Customer** is just an
email address (see `app/Models/Customer.php` — no password, no login). The
portal authenticates them two ways instead, both built on Laravel
primitives:

- a **signed URL** baked into the confirmation email, and
- a manual **email + access key** lookup.

Files to read alongside this doc:

- `routes/web.php` — the `portal` route group (three middleware stacks)
- `resources/views/layouts/portal.blade.php` — the public layout
- `resources/views/pages/portal/⚡{home,submit,lookup,status}.blade.php`
- `app/Mail/NewRequestConfirmationMail.php` + `resources/views/mail/new-request-confirmation.blade.php` (built in Phase 3)
- `app/Actions/Requests/{CreateRequest,AddNote,ChangeStatus}.php` (reused from Phase 2)
- `tests/Feature/Portal/`

## 1. Two layouts, two audiences

A Laravel app can have as many Blade layouts as it has distinct chromes.
HelpStripe has two:

- `layouts/app.blade.php` — the authenticated agent shell: sidebar, team
  switcher, user menu. Assumes a logged-in `User`.
- `layouts/portal.blade.php` — the public shell: a brand header, a thin nav,
  a footer, and nothing else. There's no user to greet.

A Livewire page opts into a layout with the `#[Layout]` attribute:

```php
new #[Layout('layouts::portal')] #[Title('Submit a request')] class extends Component { /* … */ };
```

Every portal page uses `layouts::portal`; every agent page uses
`layouts::app`. The contrast *is* the lesson — pick the chrome that fits the
audience.

The portal nav links are each wrapped in a `Route::has(...)` guard:

```blade
@if (Route::has('portal.submit'))
    <flux:link :href="route('portal.submit')" wire:navigate>{{ __('Submit a request') }}</flux:link>
@endif
```

That's because the knowledge base (Phase 5) and this portal (Phase 4) were
built in either order — the guard means a link only appears once its phase
has shipped, so the two phases stay independent.

## 2. Three middleware stacks in one route group

Open the `portal` group in `routes/web.php`. It registers public routes with
*three different* middleware stacks, and contrasting them is the core routing
lesson of the phase:

| Route | Middleware | Why |
| --- | --- | --- |
| `portal.home`, `portal.kb.*` | none | Anyone can browse. |
| `portal.submit`, `portal.lookup` | `throttle:10,1` | Write-ish endpoints; rate-limited against spam / key brute-forcing. |
| `portal.status` (signed link) | `signed` | The HMAC signature *is* the credential. |
| `portal.status.show` (after lookup) | none (session-checked in the component) | Verified by a session flag a prior lookup set. |

Compare this with the authenticated `{current_team}` group right below it:
`['auth', 'verified', EnsureTeamMembership::class]`. Same router, completely
different trust model — public-with-throttling vs. logged-in-team-member.

**Registration order matters.** The `portal` group must come *before* the
`{current_team}` group, or `/portal/...` would be captured as a team slug
(`{current_team}` matches any first URL segment). This constraint predates
Phase 4 (Phase 5 hit it first); Phase 4's routes simply join the same group.

### Throttling, taught explicitly

`throttle:10,1` allows 10 requests per minute per client before returning a
raw `429 Too Many Requests`. We let the framework's bare 429 through rather
than dressing it up — fine for a teaching repo. In production you'd add a
captcha or honeypot on submit too; that's named here as the gap, not built.

> One subtlety worth knowing: the throttle sits on the *route* (the GET that
> loads the page), not on the Livewire form POST (which goes to
> `/livewire/update`). Livewire only re-applies a fixed set of "persistent"
> middleware to its update requests, and `throttle` isn't one of them (it
> can't be — persistent middleware doesn't take arguments). So the cap here
> throttles page loads. `SubmitRequestTest` asserts the 11th GET to
> `/portal/submit` in a minute returns 429.

## 3. Submit: reuse the one write-path

`pages/portal/⚡submit.blade.php` is a Livewire form (name, email, optional
category, subject, body). On submit it does three things:

1. **Resolve the customer** by lowercased email — `firstOrCreate`-style,
   exactly like the inbound email pipeline and the API. The same person
   writing in twice (or with different casing) reuses one `Customer` row;
   an existing customer keeps their *original* display name even if they
   type a new one this time.
2. **Open the request** through `CreateRequest::handle(..., RequestSource::Portal, ...)`
   — the *same action* the agent UI, email pipeline, and API call. That's
   deliberate: `RequestCreated` fires, the opening note lands inside a
   transaction, and `first_responded_at` semantics stay owned in one place,
   no matter which channel a request came from. The portal never writes a
   parallel path.
3. **Send the confirmation email** —
   `Mail::to($customer->email)->queue(new NewRequestConfirmationMail($request))`,
   the same line `ProcessInboundEmail` runs for an email-borne request.

Then the form is replaced by a confirmation panel showing the request
**number only**. The access key is *never* shown on the page — it rides the
email. That forces the email round-trip (matching HelpSpot) and keeps the
key off any screen a shoulder-surfer might see.

## 4. The access key

Where does the key come from? `Request::boot()` (Phase 1) derives it in a
`creating` model event:

```php
static::creating(function (Request $request) {
    if (empty($request->access_key)) {
        $request->access_key = Str::random(12);
    }
});
```

12 random characters. Combined with the route throttle, that's the brute-force
budget: an attacker would need an unreasonable number of guesses per minute
to find a valid key, and the throttle caps them at 10. The residual risk
(scripted guessing over a long time) is *named*, not eliminated — a teaching
repo's honest tradeoff.

## 5. Two ways into the status page

Both land on `pages/portal/⚡status.blade.php`, mounted by two routes:

**(a) The signed link** (`portal.status`). The confirmation email contains a
URL built with `URL::signedRoute('portal.status', ['request' => $id])`. The
`signed` route middleware verifies the HMAC signature (keyed on `APP_KEY`) on
every hit — a tampered query string or a different request id 403s. No
session, no password: a valid signature *is* proof the URL came from us.

**(b) Manual lookup** (`portal.lookup` → `portal.status.show`). The lookup
form takes email + access key:

- email matched case-insensitively (same as every channel),
- access key matched **exactly** (it's a random secret, not a human
  identifier — case-sensitive exact match).

On a match, the component sets a session flag `portal.verified.{id}` and
redirects to the *unsigned* status route, whose component checks that flag in
`mount()` and 403s without it. A signed visit also writes that flag, so a
returning visitor who clicked the email link can reply without re-entering
the key.

### Generic failure: enumeration resistance

A failed lookup returns *one* message — "We couldn't find a matching
request" — whether the email is unknown or the key is wrong. A field-specific
hint ("no account with that email") would let an attacker confirm which
emails exist. `LookupTest` asserts both failure modes produce the same
generic error.

## 6. The timeline shows public notes only

The status page renders the request's notes, but the query filters
`is_private = false`:

```php
$this->helpdeskRequest->notes()->where('is_private', false)->/* … */->get();
```

Private staff notes — the internal back-channel from Phase 2 — must never
reach a customer. The filter is at the *query*, not the view, so a private
note's body never even reaches the rendered HTML. `LookupTest` has a
dedicated assertion: a public reply is visible, a private note's body is
not. This is the phase's headline failure-mode guard (a timeline partial
reused without the flag would leak confidential notes).

## 7. Customer replies reopen resolved requests

The reply box routes through `AddNote::handle(..., $customer, ..., source: RequestSource::Portal)`
— authored by the `Customer`, public, on the same action staff use. If the
request was `Resolved` or `Closed`, the reply reopens it to `Active` via
`ChangeStatus` (which owns the `resolved_at` bookkeeping). The logic mirrors a
customer's *email* reply exactly: it isn't resolved if they're still writing
in. `CustomerReplyTest` covers all three cases (active stays active, resolved
and closed both reopen).

## 8. Demo script

```bash
php artisan migrate:fresh --seed
composer run dev    # serves the app + runs a queue worker
```

The seeder prints a ready-to-use set of portal credentials at the end:

```
Portal demo — check status at /portal/lookup with:
  Request:    #1 (…)
  Email:      anjali.murray@example.net
  Access key: FzGF6qn4iwuT
```

(Exact values vary — faker provides the email; the key is random.)

1. **Start at `/`.** The welcome page has a **Support portal** button →
   `/portal`.
2. **Submit a request.** Click *Submit a request*, fill it in with your own
   email, submit. You'll see the request number; the access key is in the
   email.
3. **Grab the key.** With `MAIL_MAILER=log` (the default), the confirmation
   email is written to `storage/logs/laravel.log` — find the access key
   there. (With `MAIL_MAILER=resend` and a wired domain it lands in your real
   inbox — see [03-shared-inbox.md](03-shared-inbox.md) §9B for the one-time
   setup. The signed status link is in that email too.)
4. **Check status two ways.** Click the signed link from the email (no key
   needed), *or* go to `/portal/lookup` and enter email + key. Both land on
   the status page.
5. **Reply.** Add a reply from the status page. If you resolved the request
   first (step 6), the reply reopens it.
6. **Switch to the agent side.** Log in as `sam@helpstripe.test`
   (password `password`), open the queue, and find your new request: it
   arrived with **source = Portal**, and your customer reply is on its
   timeline. Resolve it, then reply again from the portal — watch it reopen.

If you don't want to set up mail at all, use the seeder-printed credentials
(step 0 above) to drive the email+key lookup path immediately.

### What's intentionally left out

- **No captcha / honeypot** on submit — named as the production gap; the
  route throttle is the only spam control here.
- **Session-based verified access** is deliberately simpler than real auth.
  It's a per-request flag, not a login. Good enough for a status page;
  explicitly *not* how you'd gate sensitive data.
- **APP_KEY rotation** invalidates old signed links (they'll 403). The manual
  email+key lookup always works, so it's the recovery path — noted here so a
  rotated key doesn't read as a bug.

## 9. Tests

```bash
php artisan test --compact --filter=Portal
```

- `SubmitRequestTest` — happy path (Portal source, mail queued, opening
  note), validation, customer dedup by email, foreign-category drop, route
  throttle (429 on the 11th request).
- `LookupTest` — signed link opens the page; tampered signature 403s; unsigned
  route 403s without a session; correct/case-insensitive email + exact key
  verifies and redirects; wrong key and unknown email both show the *same*
  generic error; **private notes never leak**.
- `CustomerReplyTest` — reply stored as a public, customer-authored Portal
  note; reopens Resolved/Closed to Active; leaves Active unchanged; appears in
  the timeline immediately.
