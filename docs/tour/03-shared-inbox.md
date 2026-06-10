# 03 — Shared Inbox & Email Pipeline

This phase turns HelpStripe into an *email* product. Three intake channels
now converge on the Phase 2 write-path (`CreateRequest` / `AddNote`):

1. **Real inbound email** — Resend receives mail for the domain, posts a
   webhook, and a queued job files it as a request (or threads it onto an
   existing one).
2. **The agent UI** — unchanged from Phase 2.
3. **A token-authed JSON API** — `POST /api/v1/requests`.

Outbound goes the other way: a staff member's public reply becomes a real
email to the customer, carrying threading headers so it lands in the same
conversation in their mail client — and so *their* reply comes back
matchable to the request.

Files to read alongside this doc:

- `config/mail.php`, `config/services.php`, `.env.example` — the `resend` mailer
- `app/Mail/{PublicReplyMail,NewRequestConfirmationMail}.php` + `resources/views/mail/`
- `app/Listeners/SendPublicReplyEmail.php`
- `config/webhook-client.php`, `bootstrap/app.php`, `routes/web.php` (webhook route)
- `app/Support/Resend/{ResendSignatureValidator,InboundEmail}.php`
- `app/Jobs/ProcessInboundEmail.php`
- `app/Console/Commands/ReplayInboundMail.php`
- `routes/api.php`, `app/Http/Controllers/Api/V1/RequestController.php`,
  `app/Http/Requests/Api/StoreRequestRequest.php`,
  `app/Http/Resources/RequestResource.php`,
  `app/Http/Middleware/AuthenticateApiToken.php`
- `tests/Fixtures/resend/`, `tests/Feature/Inbox/`, `tests/Feature/Api/`

## 1. Mailers and transports

A *mailer* is a named delivery channel in `config/mail.php`. The repo ships
two that matter here:

- `log` — writes the rendered message to `storage/logs/laravel.log`. This is
  the default in `.env.example` (`MAIL_MAILER=log`) and what tests use
  (`MAIL_MAILER=array`, via `phpunit.xml`). No account, no network.
- `resend` — Laravel's first-party Resend transport. It needs
  `resend/resend-php` (installed this phase) and a key at
  `config('services.resend.key')`, read from `RESEND_API_KEY`.

Flip from logged to real mail with one env change: `MAIL_MAILER=resend`.
Nothing in the application code knows or cares which transport is active —
that's the point of the mailer abstraction.

## 2. Outbound: Mailables carry the threading headers

Open `app/Mail/PublicReplyMail.php`. A **Mailable** is a class that
describes one email in three methods:

- `envelope()` — from, reply-to, subject. From/reply-to is the request's
  **mailbox address** (`support@…`), never a staff member's personal
  address, so the customer's reply comes back to an address Resend receives.
- `content()` — points at a Markdown Blade view (`mail.public-reply`).
- `headers()` — the interesting part.

Email threading is three RFC 5322 headers. Every message has a unique
`Message-ID`; a reply carries `In-Reply-To` (its parent's id) and
`References` (the whole ancestor chain). Mail clients group a conversation
by walking these. So `PublicReplyMail` mints a **deterministic** Message-ID
per note —

```php
public static function messageIdFor(Note $note): string
{
    return sprintf('note-%d@%s', $note->id, config('helpstripe.inbound_domain'));
}
```

— and chains every prior note's message id into `References`. Two subtleties
worth internalizing:

- Laravel's `Headers` value object takes ids **without** angle brackets;
  Symfony Mailer adds the `<…>` on the wire. Our `InboundEmail` DTO strips
  them on the way in, so the whole app compares bare ids.
- The id is derived from the note's primary key, so a **retried** queue job
  regenerates the identical id — it can store the value on the note without
  round-tripping the sent message.

There's also a belt-and-braces `[#id]` token in the subject: some mail
clients drop `References` on reply, but almost all preserve the subject. The
inbound matcher falls back to it (§6).

## 3. The send is a queued listener, not inline code

Open `app/Listeners/SendPublicReplyEmail.php`. Phase 2's `AddNote` action
dispatches a `NoteAdded` event. This listener subscribes:

```php
class SendPublicReplyEmail implements ShouldQueue
{
    public function handle(NoteAdded $event): void
    {
        $note = $event->note;
        if ($note->is_private || $note->user_id === null) { return; }   // internal / customer-authored
        // …send PublicReplyMail to the customer, persist the message id…
    }
}
```

Why a listener and not code inside `AddNote`? Because `AddNote` is called by
*every* channel — agent UI, inbound email, API, portal — and none of them
should have to remember that public replies also go out by email. The action
publishes the event; side effects subscribe. (Phase 6 triggers and Phase 7
broadcasting attach to the same event without reopening the write-path.)

`ShouldQueue` defers the actual send to the queue worker, so the agent's
"post reply" click never blocks on an API round-trip. **This is why the demo
needs a running queue worker** (`composer run dev` starts one).

Laravel discovers the listener automatically — the `NoteAdded` type-hint on
`handle()` *is* the registration.

## 4. Inbound: "store, then process"

Resend receives mail and POSTs an `email.received` webhook. We use
`spatie/laravel-webhook-client`, whose architecture is **store, then
process** (`config/webhook-client.php`):

1. The package's controller verifies the signature (§5).
2. It persists the raw call as a `WebhookCall` row.
3. It dispatches a queued job (`ProcessInboundEmail`).
4. It answers **200** — in milliseconds, before any business logic runs.

The payoff: Resend never retries just because *our* processing was slow, and
a genuine processing failure lands in `failed_jobs` (inspect with `php artisan
queue:failed`) with the original payload still on the `webhook_calls` row for
replay.

The route is registered in `routes/web.php` as
`Route::webhooks('webhooks/resend', 'resend')` and exempted from CSRF in
`bootstrap/app.php` — webhook senders have no CSRF token and never will;
authenticity is the signature's job instead, a far stronger guarantee.

## 5. Signature verification (svix)

Open `app/Support/Resend/ResendSignatureValidator.php`. Resend signs with the
svix scheme: three headers (`svix-id`, `svix-timestamp`, `svix-signature`)
and a `whsec_…` shared secret. The signature is an HMAC-SHA256 over the
literal string `"{id}.{timestamp}.{raw body}"`, keyed with the
**base64-decoded** secret. Two traps, both handled and both commented in the
file:

1. **Use the raw body, byte for byte.** Re-encoding parsed JSON produces a
   different string and a failed match — so the validator reads
   `$request->getContent()`, never the parsed array.
2. **Strip the `whsec_` prefix and base64-decode the rest** before keying the
   HMAC. The prefix is not part of the key.

A `±300s` timestamp window bounds replay attacks, and `hash_equals()` does
the final comparison in constant time (closing the timing side-channel that
`===` would open). On failure the validator throws
`InvalidWebhookSignature`, which `bootstrap/app.php` renders as a **401** —
"rejected," not "your endpoint is broken" (a 5xx would trigger pointless
retries).

`tests/Feature/Inbox/InboundWebhookTest.php` builds *real* svix signatures
and posts the fixture through the full HTTP stack: valid signature → 200 and
a stored call; wrong secret, missing headers, or a stale timestamp → 401 with
nothing stored.

## 6. The job: parse, match, file

`app/Jobs/ProcessInboundEmail.php` extends the package's `ProcessWebhookJob`.
Resend's `email.received` event carries metadata only, so the job fetches the
body, headers, and attachment listing from the Resend API, then parses
everything once into the `InboundEmail` DTO (`app/Support/Resend/InboundEmail.php`
— the single parse point, so a Resend schema change touches one file).

Then it files the email:

```
1. $email   = InboundEmail::fromResend($payload, $this->fetchEmailContent($emailId));
2. $email   = $this->applyMailRules($email);          // Phase 6 seam — no-op pass-through today
3. dedupe   : a note with this Message-ID already exists? → return (redelivery)
4. $mailbox = Mailbox by the `to` address, else the first mailbox (fallback)
5. $customer= firstOrCreate by from-email, lowercased (case-insensitive)
6. $request = Request::findForInbound($email, $mailbox)  // see matching below
7. found?   AddNote(customer, public, message_id) + reopen if Resolved/Closed
   else:    CreateRequest(source: Email) + send NewRequestConfirmationMail
8. attachments → $note->addMedia(...) (medialibrary)
```

**Matching strategy** (`Request::findForInbound()`), strongest signal first:

1. **Threading headers** — the email's `In-Reply-To`/`References` ids matched
   against stored `notes.message_id`. Catches normal replies; mail clients
   preserve these automatically.
2. **Subject token** — the `[#id]` we put in every outbound subject. Survives
   clients that strip headers, and is **scoped to the receiving mailbox's
   team** so a pasted token can't land a note on another installation's
   request.
3. Neither matches → a new request opens.

**Reopen behavior:** a customer reply to a Resolved/Closed request flips it
back to Active (it isn't resolved for *them*). `ChangeStatus` owns the
`resolved_at` bookkeeping.

**Idempotency:** Resend redelivers on slow/5xx responses. The Message-ID
landed on a note the first time, so seeing it again is recognized as
redelivery, not a new message — one note, not two. The
`CreateRequest`/`AddNote` actions write the message id inside their
transaction, so the dedupe check can never observe a half-created request.

`tests/Feature/Inbox/InboundMatchingTest.php` walks the whole matrix:
header match, subject-token fallback, cross-team token rejection, unknown
`to` → fallback mailbox, resolved-reply reopen, duplicate-delivery dedupe, and
case-only customer-email collisions.

### Attachments (medialibrary)

`spatie/laravel-medialibrary` attaches files to a model. `Note` is
`InteractsWithMedia`; the job downloads each attachment to a temp file and
`$note->addMedia($path)->toMediaCollection('attachments')` *moves* it onto the
media disk. Each attachment fails independently: an oversize file (over
`config('helpstripe.max_attachment_bytes')`) or an unfetchable one becomes a
**private** timeline note ("attachment could not be imported"), never a lost
email.

## 7. mail:replay — the offline demo path

You don't need a Resend account, a verified domain, or an internet tunnel to
demo the whole inbound pipeline. `app/Console/Commands/ReplayInboundMail.php`
feeds recorded fixtures (`tests/Fixtures/resend/*.json`) through the *exact*
`ProcessInboundEmail` the live webhook queues:

```bash
php artisan mail:replay              # replays every fixture, in order
php artisan mail:replay inbound-new  # replays one
```

Each fixture bundles three payloads: the webhook body, the email content the
job fetches, and the attachment listing. The command `Http::fake()`s those
Resend reads (including the attachment binary's CDN URL), stores a
`WebhookCall` exactly like the package's controller would, runs the job
synchronously, and prints the resulting request URL. Replaying `inbound-new`
then `inbound-reply` demonstrates threading: the reply lands on the request
the first replay created, because its `References` header carries the
original's Message-ID.

## 8. The API intake channel

Open `routes/api.php`:

```php
Route::prefix('v1')->middleware(AuthenticateApiToken::class)->group(function () {
    Route::post('requests', [RequestController::class, 'store'])->name('api.v1.requests.store');
});
```

The `v1` prefix versions the contract from day one. `routes/api.php` is wired
in `bootstrap/app.php` (the first API route in the repo), which applies the
stateless `api` middleware group — no sessions, no CSRF.

`app/Http/Middleware/AuthenticateApiToken.php` reads
`Authorization: Bearer {token}` and compares it to
`config('helpstripe.api_token')` with `hash_equals()` (constant time, so the
token doesn't leak a byte at a time to a timing attacker). This is the
*teaching* implementation — Laravel **Sanctum** is the production-grade path
(per-client tokens, abilities, revocation); see Future Considerations.

The request body is validated by `StoreRequestRequest` (a Form Request — the
incoming request is validated *before* the controller runs). The
`category_id` rule scopes its `exists` check to the installation's team, so a
caller can't file under another tenant's category by guessing ids. The
controller reuses `CreateRequest` (`source: Api`) and returns **201** with a
`RequestResource`:

```jsonc
// POST /api/v1/requests
{ "subject": "Can't log in", "body": "Help!",
  "customer": { "name": "Pat", "email": "pat@example.com" }, "category_id": 2 }

// 201
{ "data": { "id": 41, "subject": "Can't log in", "status": "active",
            "source": "api", "access_key": "k3yk3yk3yk3y", "created_at": "…" } }
```

`access_key` is in the create response on purpose — it's the one moment a
caller legitimately needs it (the Phase 4 portal authenticates the customer
with email + key). It is exposed on no read or listing endpoint.

`tests/Feature/Api/CreateRequestApiTest.php`: no token → 401, wrong token →
401, malformed body → 422 with field errors, valid → 201 + the request in the
queue with an `api` source.

## 9. Demo script

### A — Offline (no external setup)

```bash
php artisan migrate:fresh --seed
composer run dev   # the queue worker matters for the reply step
```

1. **Replay all fixtures**: `php artisan mail:replay`. Three printed request
   URLs — a billing request with a PDF attachment, a new sign-in request,
   and the reply threaded onto the sign-in request (fixtures run
   alphabetically: `inbound-attachment`, `inbound-new`, `inbound-reply`).
2. **Open the created request** (paste the printed URL after logging in as
   `sam@helpstripe.test` / `password`). The customer's email is the opening
   note; the attachment request shows `receipt.pdf` on its note.
3. **Reply** from the request detail page. With the queue worker running,
   `SendPublicReplyEmail` fires and a `PublicReplyMail` is written to
   `storage/logs/laravel.log` (`MAIL_MAILER=log`) — inspect the
   `Message-ID`, `References`, and `Re: … [#id]` subject.
4. **curl the API**:

   ```bash
   curl -s -X POST "$(php artisan tinker --execute 'echo url("/api/v1/requests");')" \
     -H "Authorization: Bearer ${HELPSTRIPE_API_TOKEN}" \
     -H 'Content-Type: application/json' -H 'Accept: application/json' \
     -d '{"subject":"Can'\''t log in","body":"Help!","customer":{"name":"Pat","email":"pat@example.com"}}'
   ```

   201 with the request `data`; refresh the queue to see it with an **API**
   source badge.

### B — Live (real Resend round-trip)

One-time setup:

1. Create a Resend account; add and **verify your domain** (DNS records Resend
   lists, including the inbound **MX** record).
2. Set `HELPSTRIPE_INBOUND_DOMAIN=yourdomain.com` and re-seed — the seeded
   `support@`/`billing@` mailbox addresses now derive from it
   (`database/seeders/DemoSeeder.php`).
3. `MAIL_MAILER=resend`; set `RESEND_API_KEY`.
4. In the Resend dashboard create an **inbound webhook** subscribed to
   `email.received`, pointed at your `…/webhooks/resend` URL (expose your
   local app with **Herd share** or **Expose** so Resend can reach it). Copy
   the `whsec_…` signing secret into `RESEND_WEBHOOK_SECRET`.

Then:

1. **Send a real email** to `support@yourdomain.com`. The webhook fires, the
   queued job runs, and a request appears in the queue.
2. **Reply** from the request. It arrives in the customer's real inbox,
   threaded into the original conversation (Gmail's conversation view groups
   it).
3. **Reply from the customer's mailbox.** It comes back carrying the
   `In-Reply-To` we sent under — `findForInbound()` matches it to the request
   and the reply joins the timeline.

## 10. Out of scope / Future Considerations

- **Body sanitization** — we store the text part (or `strip_tags` on HTML).
  Stripping quoted replies, tracking pixels, and inline CSS is a product
  concern, not a teaching one.
- **Sanctum** — the static bearer token is for teaching middleware + config.
  Production wants per-client tokens with abilities and revocation.
- **Mail Rules** — `ProcessInboundEmail::applyMailRules()` is a no-op
  pass-through seam Phase 6 fills, so the rule engine arrives without
  reopening the pipeline.

## 11. Verify

```bash
php artisan test --compact --filter=Inbox            # webhook, matching, outbound, replay, attachments
php artisan test --compact --filter=CreateRequestApi # the API channel
./init.sh                                            # lint + static analysis + full suite
```
