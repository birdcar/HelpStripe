# Implementation Spec: HelpStripe - Phase 4: Self-Service Portal

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

The portal is the customer-facing, unauthenticated half of HelpStripe, mirroring real HelpSpot: no customer accounts. Customers submit a request through a public form, receive `NewRequestConfirmationMail` (built in Phase 3) containing their request number, access key, and a signed status URL, and can return to check status either via the signed link or by entering email + access key. They can also add a reply from the status page, which flows through the same `AddNote` action as email replies.

The portal gets its own minimal layout (`layouts/portal.blade.php`) — no app sidebar, simple branded header — teaching the multiple-layouts pattern. Routes live in a `Route::prefix('portal')->name('portal.')` group with throttling on the submit and lookup endpoints. The contrast between this public group and the authed `{current_team}` group is the core lesson of the phase, alongside signed URLs and rate limiting.

Knowledge Base links on the portal home render only when Phase 5 has shipped (`Route::has('portal.kb.index')` guard), so phases 4 and 5 stay order-independent.

## Feedback Strategy

**Inner-loop command**: `php artisan test --compact --filter=Portal`

**Playground**: Browser at `/portal` (no login needed) + Pest feature tests for flows.

**Why this approach**: Small set of public pages; HTTP-level tests cover flows fast, browser confirms the layout.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `resources/views/layouts/portal.blade.php` | Public layout: brand header, footer, no app chrome |
| `resources/views/pages/portal/⚡home.blade.php` | Portal landing: submit CTA, status-check CTA, KB teaser (conditional) |
| `resources/views/pages/portal/⚡submit.blade.php` | Request form: name, email, category, subject, body |
| `resources/views/pages/portal/⚡lookup.blade.php` | Email + access key form |
| `resources/views/pages/portal/⚡status.blade.php` | Customer view of a request: public notes timeline + reply box |
| `tests/Feature/Portal/SubmitRequestTest.php` | Submission flow + confirmation mail |
| `tests/Feature/Portal/LookupTest.php` | Email+key lookup, signed URL access, wrong-key rejection |
| `tests/Feature/Portal/CustomerReplyTest.php` | Reply from status page; reopen behavior |
| `docs/tour/04-portal.md` | Tour doc: public routes, layouts, signed URLs, throttling + demo script |

### Modified Files

| File Path | Changes |
| --- | --- |
| `routes/web.php` | `portal` route group (public, throttled) |
| `app/Actions/Requests/CreateRequest.php` | Ensure source=Portal path sends `NewRequestConfirmationMail` (queued) |
| `resources/views/welcome.blade.php` | Link to the portal so demos start from `/` |
| `database/seeders/DemoSeeder.php` | Ensure at least one seeded request's access key is printed in seeder output for demo convenience |

## Implementation Details

### Portal layout + home

**Pattern to follow**: `resources/views/layouts/auth/card.blade.php` (minimal alternate layout)

**Overview**: Slim Blade layout using Flux/Tailwind, brand name + "Submit a request" / "Check a request" nav. Home page is static content with the two CTAs and a conditional KB section.

No feedback loop — near-static markup, verified by smoke test + browser.

### Submit flow (⚡submit)

**Pattern to follow**: `resources/views/pages/auth/register.blade.php` (public Livewire form)

**Overview**: Livewire form: customer name, email, category select (optional), subject, body. On submit: `Customer::firstOrCreate` by email, `CreateRequest` with `source: Portal`, then redirect to a confirmation state showing the request number and "we've emailed your access key".

**Key decisions**:
- Confirmation email (not the page) carries the access key — matches HelpSpot and forces the email round-trip in demos. The page shows number only.
- Throttle: `throttle:10,1` on the route — taught explicitly.
- Honeypot/captcha out of scope; named in tour doc as the production gap.

**Implementation steps**:
1. Route + form + validation rules.
2. Wire CreateRequest + confirmation mail assertion.
3. Confirmation state + redirect.

**Feedback loop**:
- **Playground**: `SubmitRequestTest` + browser form.
- **Experiment**: valid submit → request exists with Portal source, mail queued to submitter; invalid email/empty subject → inline errors; 11th submit in a minute → 429.
- **Check command**: `php artisan test --compact --filter=SubmitRequestTest`

### Lookup + status (⚡lookup, ⚡status)

**Overview**: Two access paths to the same status page: (a) signed URL from the confirmation email (`URL::signedRoute('portal.status', [request, access_key-hash?])`); (b) manual form posting email + access key, which resolves the request or shows a generic "no match" error (no enumeration of which field was wrong). Status page renders subject, status badge, public notes only (private notes invisible — asserted in tests), and a reply textarea that calls `AddNote` as the customer (reopening resolved requests, consistent with email replies).

**Key decisions**:
- Signed route teaches `URL::signedRoute` + `signed` middleware; manual lookup teaches a custom resolution flow. Both land on one route handler.
- The status page session-remembers verified access (so replying doesn't re-prompt), via a simple session key — named as deliberately simpler than real auth.
- Generic error on failed lookup — an enumeration-resistance lesson.

**Implementation steps**:
1. Status route with `signed` middleware path + session-verified path.
2. Lookup form → verify → session flag → redirect to status.
3. Public-notes-only timeline (reuse timeline partial from Phase 2 with a `public-only` flag, or a slim portal variant).
4. Customer reply box → AddNote(customer) → reopen logic assertion.

**Feedback loop**:
- **Playground**: `LookupTest` / `CustomerReplyTest`; browser with seeder-printed access key.
- **Experiment**: correct email+key → status; right email wrong key → generic error; tampered signed URL → 403; private notes absent from HTML; reply on Resolved request → status Active again.
- **Check command**: `php artisan test --compact --filter=LookupTest`

### Tour doc 04

Covers: route groups + middleware stacks (public vs auth vs signed), layouts, throttling, signed URLs, session basics. Demo script: from `/`, submit a request, grab the access key from the logged/Resend email, check status via both paths, customer-reply, then switch to the agent queue to show the request arrived with Portal source and the reply reopened it.

## Testing Requirements

| Test File | Coverage |
| --- | --- |
| `SubmitRequestTest` | Happy path, validation, throttle, mail queued, customer dedup by email |
| `LookupTest` | Both access paths, generic failure, signature tampering, no private notes leaked |
| `CustomerReplyTest` | Reply persists as customer-authored public note; reopens resolved request; throttled |

**Key edge cases**: existing customer submitting with a new display name (keep original name, note in doc); request with zero public notes renders empty-state; access key lookup is case-sensitive exact match.

### Manual Testing

- [ ] Full demo script in tour doc, including the live-email variant via Resend
- [ ] Portal renders sanely on mobile width (Flux/Tailwind responsive defaults)

## Error Handling

| Error Scenario | Handling Strategy |
| --- | --- |
| Failed lookup | Generic "We couldn't find a matching request" — no field-level hint |
| Expired/tampered signature | 403 page with link back to manual lookup |
| Throttle exceeded | Framework 429; acceptable raw for teaching repo |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
| --- | --- | --- | --- | --- |
| Lookup | Access-key brute force | scripted guessing | request data exposure | 12-char random key + throttle; named residual risk in doc |
| Status page | Private note leak | timeline partial reused without flag | confidential staff notes exposed | dedicated test asserting absence of private note body |
| Confirmation mail | Queue not running | demo without queue worker | "no email" confusion | demo script starts queue; seeder prints a known access key as fallback |
| Signed URL | APP_KEY rotation | key changes between email and click | 403 for old links | manual lookup path always works; noted in doc |

## Validation Commands

```bash
composer lint
php artisan test --compact --filter=Portal
composer test
./init.sh
```

## Rollout Considerations

None. Update `feature_list.json` + `progress.md` with evidence on completion.
