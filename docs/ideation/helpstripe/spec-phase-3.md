# Implementation Spec: HelpStripe - Phase 3: Shared Inbox & Email Pipeline

**Contract**: ./contract.md
**Estimated Effort**: L

## Technical Approach

This phase makes HelpStripe an email product. Three intake channels converge on the Phase 2 `CreateRequest`/`AddNote` actions: real inbound email (Resend inbound webhooks → `spatie/laravel-webhook-client` → queued job), the existing agent UI, and a new token-authed JSON API. Outbound goes through Laravel's native Resend mail transport: agent public replies become customer-facing Mailables carrying threading headers (`Message-ID`, `In-Reply-To`, `References`) so replies land in the same conversation in the customer's mail client, and so *their* replies can be matched back to the request.

Inbound matching strategy (in order): (1) `In-Reply-To`/`References` headers matched against stored `notes.message_id`; (2) subject token `[#{id}]` regex; (3) otherwise a new request is created against the Mailbox matching the `to` address (falling back to the first mailbox), with the mailbox's default category. A customer reply to a Resolved/Closed request reopens it (status → Active) — real HelpSpot behavior worth demoing.

Demoability without the internet: `php artisan mail:replay {fixture}` feeds recorded Resend payloads (stored as test fixtures) through the same processing job the webhook uses. Live demos use the wired domain + Herd's share/Expose tunnel. **Verification note for implementer**: confirm the exact Resend inbound payload shape and signing scheme (svix-style headers) against current Resend docs before writing the signature validator and fixtures; capture one real payload into `tests/Fixtures/resend/` as ground truth.

## Feedback Strategy

**Inner-loop command**: `php artisan test --compact --filter=Inbox`

**Playground**: Pest tests posting fixture payloads to the webhook endpoint (full HTTP stack), plus `php artisan mail:replay` against the seeded app in the browser. Outbound inspected via the `log` mailer in tests (`Mail::fake()`) and a real Resend send in manual verification.

**Why this approach**: Pipeline logic dominates; fixture-driven webhook tests give second-scale iteration without network. Real sends are manual-verification only.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `config/helpstripe.php` | App config: API token, inbound domain, default mailbox behavior |
| `app/Mail/PublicReplyMail.php` | Customer-facing reply Mailable with threading headers |
| `app/Mail/NewRequestConfirmationMail.php` | Confirmation w/ request number + access key (used by Portal phase too) |
| `resources/views/mail/public-reply.blade.php` | Markdown mail view |
| `resources/views/mail/new-request-confirmation.blade.php` | Markdown mail view |
| `app/Listeners/SendPublicReplyEmail.php` | On NoteAdded (public, staff-authored, email-originated request) → queue PublicReplyMail |
| `app/Jobs/ProcessInboundEmail.php` | Parse Resend payload → match/create request → add note → attachments |
| `app/Support/Resend/ResendSignatureValidator.php` | spatie webhook-client SignatureValidator for Resend/svix signing |
| `app/Support/Resend/InboundEmail.php` | DTO wrapping the webhook payload (from, to, subject, text/html, headers, attachments) |
| `app/Console/Commands/ReplayInboundMail.php` | `mail:replay {fixture?}` — runs fixtures through ProcessInboundEmail |
| `app/Http/Controllers/Api/V1/RequestController.php` | POST /api/v1/requests (store) |
| `app/Http/Resources/RequestResource.php` | API resource for Request |
| `app/Http/Requests/Api/StoreRequestRequest.php` | Form request validation (note the alias lesson: `App\Models\Request as HelpdeskRequest`) |
| `app/Http/Middleware/AuthenticateApiToken.php` | Bearer-token check against config |
| `routes/api.php` | v1 group |
| `tests/Fixtures/resend/inbound-new.json` | Fixture: fresh email, no threading |
| `tests/Fixtures/resend/inbound-reply.json` | Fixture: reply with In-Reply-To |
| `tests/Fixtures/resend/inbound-attachment.json` | Fixture: email with attachment |
| `tests/Feature/Inbox/InboundWebhookTest.php` | Signature validation + end-to-end webhook → request |
| `tests/Feature/Inbox/InboundMatchingTest.php` | Threading/subject-token/new-request matching matrix |
| `tests/Feature/Inbox/OutboundReplyTest.php` | Mailable headers, recipient, listener wiring |
| `tests/Feature/Inbox/ReplayCommandTest.php` | mail:replay processes fixtures |
| `tests/Feature/Api/CreateRequestApiTest.php` | API channel auth + creation |
| `docs/tour/03-shared-inbox.md` | Tour doc: mail pipeline, queues/jobs, webhooks, signature validation, API resources + demo script (incl. Resend domain + Herd share setup) |

### Modified Files

| File Path | Changes |
| --- | --- |
| `composer.json` | `composer require resend/resend-php spatie/laravel-webhook-client spatie/laravel-medialibrary` |
| `config/mail.php` / `.env.example` | `resend` mailer; `RESEND_KEY`, `MAIL_MAILER` docs; per-mailbox from addresses |
| `config/webhook-client.php` | Resend endpoint config + validator + ProcessInboundEmail wiring |
| `bootstrap/app.php` or `routes/web.php` | Webhook route (`Route::webhooks('webhooks/resend')`) excluded from CSRF |
| `app/Models/Request.php` | `HasMedia` (attachments via notes? see decision), helper `findForInbound()` |
| `app/Models/Note.php` | `InteractsWithMedia` — attachments attach to the note |
| `app/Actions/Requests/AddNote.php` | Accept optional message_id + attachments array |
| `database/seeders/DemoSeeder.php` | Mailbox addresses match the wired Resend domain pattern |

## Implementation Details

### Outbound: PublicReplyMail + listener

**Pattern to follow**: Laravel mail docs via Boost `search-docs` `['mailable headers message-id', 'markdown mailables', 'resend transport']`

**Overview**: `SendPublicReplyEmail` listens to `NoteAdded`; guards: note is public, staff-authored, and the request has a customer email. Builds `PublicReplyMail` with: to = customer, from/reply-to = request's mailbox address, subject `Re: {subject} [#{id}]`, `headers()` returning `messageId: "<note-{id}@{inbound-domain}>"` and `references` chaining prior note message_ids. After send, persist the generated message_id on the note.

**Key decisions**:
- Listener (not inline in AddNote) — teaches event-driven side effects and keeps portal/API reuse clean.
- `ShouldQueue` on the listener; demo script runs the queue.
- Subject token `[#{id}]` is belt-and-braces alongside header threading.

**Feedback loop**:
- **Playground**: `OutboundReplyTest` with `Mail::fake()`.
- **Experiment**: public staff note → queued mail with correct headers/recipient; private note → none; customer-authored note → none; second reply references first.
- **Check command**: `php artisan test --compact --filter=OutboundReplyTest`

### Inbound: webhook → ProcessInboundEmail

**Pattern to follow**: spatie/laravel-webhook-client docs (custom `SignatureValidator`, `ProcessWebhookJob`)

**Overview**: Resend posts `email.received` events. webhook-client validates the svix-style signature (`ResendSignatureValidator` using the signing secret from config), stores the call, and dispatches `ProcessInboundEmail` (extends `ProcessWebhookJob`). The job wraps the payload in the `InboundEmail` DTO, then:

```php
1. $email = InboundEmail::fromWebhookPayload($payload)
2. $customer = Customer::firstOrCreate(by from-email, name from display part)
3. $request = Request::findForInbound($email)   // headers → subject token → null
4. if found: AddNote(customer note, public, message_id) + reopen if Resolved/Closed
   else: mailbox = Mailbox::where('address', $email->to)->first() ?? Mailbox::first();
         CreateRequest(source: Email, mailbox, category: mailbox->category, subject, body, message_id)
5. attachments: download/decode per Resend's scheme → $note->addMedia(...) (medialibrary)
```

**Key decisions**:
- Job is idempotent on Resend's event id (skip if a note with that message_id exists) — webhook redelivery is the named failure.
- HTML bodies: store text part when present; else `strip_tags` on HTML. Body sanitization beyond that is out of scope (teaching repo) but named in the tour doc.
- Mail Rules hook point: the job exposes a single `applyMailRules($email)` seam that Phase 6 fills; this phase ships it as a no-op pass-through so Phase 6 doesn't reopen the pipeline.

**Implementation steps**:
1. Install packages, publish webhook-client config + medialibrary migrations.
2. Fixtures first (from Resend docs / one real capture).
3. Signature validator + route; `InboundWebhookTest` (valid sig 200, invalid 401).
4. DTO + matching helper + job happy paths.
5. Reopen behavior, idempotency, attachments.
6. `mail:replay` command (reads fixture JSON, constructs a fake `WebhookCall`, runs the job synchronously, prints the resulting request URL).

**Feedback loop**:
- **Playground**: `InboundMatchingTest` + `php artisan mail:replay inbound-new` against seeded app.
- **Experiment**: matrix — reply-header match, subject-token match, no match → new request; unknown `to` address → fallback mailbox; resolved request reply → reopened; duplicate delivery → single note.
- **Check command**: `php artisan test --compact --filter=InboundMatchingTest`

### API intake channel

**Pattern to follow**: Laravel API resources via Boost `search-docs` `['api resources', 'form request validation']`

**Overview**: `POST /api/v1/requests` with `Authorization: Bearer {token}` (static token in `config/helpstripe.php` from env). Body: `{subject, body, customer: {name, email}, category_id?}` → CreateRequest(source: Api) → 201 with RequestResource `{id, number, subject, status, access_key, created_at}`.

**Key decisions**:
- Static bearer token via tiny middleware — teaches middleware + config; Sanctum noted in tour doc as the production-grade path (Future Considerations).
- Versioned `v1` prefix per repo API guidance.

**Feedback loop**:
- **Playground**: `CreateRequestApiTest`; curl one-liner in tour doc.
- **Experiment**: no token → 401; bad payload → 422 with field errors; valid → 201, request visible in queue with Api source badge.
- **Check command**: `php artisan test --compact --filter=CreateRequestApiTest`

### Tour doc 03

Covers: mailers/transports (log vs resend), Mailables + headers, queued listeners, webhook-client architecture (store-then-process), signature verification, jobs/retries, medialibrary, API resources. Demo script: (a) offline — `mail:replay` all three fixtures, watch queue, open created request, reply, see logged mail; (b) live — Resend domain + webhook endpoint via Herd share, send a real email, reply from the queue, see it in the inbox threaded. Includes the one-time Resend setup checklist (domain, MX, webhook secret envs).

## API Design

| Method | Path | Description |
| --- | --- | --- |
| `POST` | `/api/v1/requests` | Create a request (third intake channel) |

```jsonc
// Request
{ "subject": "Can't log in", "body": "Help!", "customer": { "name": "Pat", "email": "pat@example.com" }, "category_id": 2 }
// 201 Response
{ "data": { "id": 41, "subject": "Can't log in", "status": "active", "source": "api", "access_key": "k3yk3yk3yk3y", "created_at": "..." } }
```

## Testing Requirements

Listed per component above. **Key edge cases**: malformed payload (missing from/subject) → job fails loudly to failed_jobs, webhook still 200s (store-then-process semantics — named in tour doc); empty-body email; attachment over a size cap (skip + private note saying so); customer email differing only by case.

### Manual Testing

- [ ] Live email → request appears; agent reply threads in real inbox (Gmail conversation view)
- [ ] `mail:replay inbound-attachment` attaches file visible on the note
- [ ] curl API call from tour doc returns 201

## Error Handling

| Error Scenario | Handling Strategy |
| --- | --- |
| Invalid webhook signature | 401 from validator; nothing stored |
| Unparseable payload | Job throws → failed_jobs; visible via `php artisan queue:failed` (taught in doc) |
| Resend send failure | Queued mail retries (3x default); failure lands in failed_jobs |
| Attachment fetch failure | Catch per-attachment, add private note "attachment could not be imported", continue |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
| --- | --- | --- | --- | --- |
| Webhook endpoint | Redelivery duplicates | Resend retries on slow response | duplicate notes/requests | idempotency check on message/event id |
| Matching | False-positive subject token | customer pastes `[#12]` of another team's request | note lands on wrong request | scope matching to mailbox's team; header match first |
| Threading | Customer client strips headers | some clients drop References | reply creates new request | subject token fallback; named acceptable failure |
| Outbound | Wrong from-domain | mailbox address not on verified Resend domain | send rejected | seeder + config derive addresses from configured domain; setup checklist |
| Replay command | Fixture drift | Resend changes payload schema | replay diverges from live | fixtures refreshed from a real capture; DTO is the single parse point |

## Validation Commands

```bash
composer lint
php artisan test --compact --filter=Inbox
php artisan test --compact --filter=CreateRequestApiTest
composer test
./init.sh
```

## Rollout Considerations

Requires one-time external setup (documented in tour doc): Resend account, verified domain + inbound MX, webhook pointing at the Herd-shared URL, `RESEND_KEY`/`RESEND_WEBHOOK_SECRET` in `.env`. Update `feature_list.json` + `progress.md` on completion.

## Open Items

- [ ] Capture a real Resend `email.received` payload to ground the fixtures (do this first during implementation).
- [ ] Confirm Resend attachment delivery mechanism (inline base64 vs fetch URL) against current docs.
