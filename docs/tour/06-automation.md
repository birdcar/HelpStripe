# 06 — Automation Rules

HelpSpot's fifth pillar is automation, and it comes in three flavors: **Mail
Rules** that act on inbound email, **Triggers** that react to events, and
**Automation Rules** that run on a schedule. HelpStripe implements all three
on one shared engine — a small `RuleEngine` over a single `automation_rules`
table — so the lesson is really about *where the same logic plugs into
different parts of the request lifecycle*.

The Laravel lessons here: JSON casts mapped to value objects, queued event
listeners, the scheduler (`routes/console.php` + `schedule:work`), service
classes, recursion guards, and activity-log causation.

Files to read alongside this doc:

- `app/Models/AutomationRule.php` — one model, three layers (JSON casts → VOs)
- `app/Enums/{RuleLayer,ConditionField,ConditionOperator,RuleAction}.php`
- `app/Support/Automation/Condition.php` + `Action.php` — the value objects
- `app/Support/Automation/ConditionEvaluator.php` — the pure matcher
- `app/Support/Automation/ActionApplier.php` — effects via the Phase 2 actions
- `app/Support/Automation/RuleEngine.php` — orchestration + the loop guard
- `app/Support/Automation/MailRuleEvaluator.php` — mail-layer overrides
- `app/Listeners/EvaluateTriggers.php` — the queued trigger listener
- `app/Console/Commands/RunAutomationRules.php` — `automation:run`
- `app/Jobs/ProcessInboundEmail.php` — the mail-rule seam, now filled
- `routes/console.php` — the scheduler entry
- `resources/views/pages/automation/⚡{index,edit}.blade.php` — the builder
- `tests/Feature/Automation/`

## 1. One table, three layers

The design decision worth pausing on: all three automation kinds share **one**
`automation_rules` table with a `layer` enum (`mail` / `trigger` /
`scheduled`). The shapes are identical — a name, an ordering position, an
active flag, and two JSON blobs (`conditions` + `actions`) — so a single
model, migration, and engine cover all three.

The alternative is three tables (`mail_rules`, `triggers`,
`automation_rules`). That would let each carry only the columns it needs (no
nullable `event` on mail/scheduled rows) and read more self-documentingly. The
tradeoff we took: one table is far less code and the layers genuinely *are* the
same shape, at the cost of a couple of layer-specific columns being nullable
and the builder UI hiding what doesn't apply per layer. For a teaching repo —
and honestly for most real apps at this scale — one table wins.

## 2. JSON casts → value objects

`conditions` and `actions` are `json` columns, cast to `array` (the same cast
`Filter::criteria` uses). But the engine never works with raw arrays. The
model exposes `hydratedConditions()` / `hydratedActions()` that map each array
row through `Condition::fromArray()` / `Action::fromArray()` into small
`final readonly` value objects:

```php
$rule->conditions          // [['field' => 'subject', 'operator' => 'contains', ...]]
$rule->hydratedConditions() // [Condition, Condition, ...]
```

This is the casts → value-object mapping done by hand — no heavyweight library.
The payoff is that a malformed, hand-edited rule fails loudly *at hydration*
(`fromArray` throws on an unknown enum value), where `RuleEngine` catches it,
logs it, and skips that one rule — instead of mis-evaluating silently three
layers deep. (The methods are named `hydrated*`, not `conditions()`, so they
don't shadow the cast attribute in Eloquent's resolution.)

## 3. The engine core

Three small service classes, each with one job:

- **`ConditionEvaluator`** — `matches(array $conditions, Request|InboundEmail
  $subject): bool`. AND across rows (HelpSpot-style; OR is a Future
  Consideration). Empty conditions match everything. The only per-subject-type
  logic is *field resolution*: `from_email`/`to_mailbox` resolve off an
  InboundEmail, `age_hours`/`category`/`status` off a Request. A field that
  has no meaning for the current subject resolves to `null` and therefore
  doesn't match anything except `is_null`. This is pure logic — no DB writes —
  which is why `ConditionEvaluatorTest` is the fastest, densest test in the
  suite.

- **`ActionApplier`** — executes a rule's actions through the **Phase 2 action
  classes** (`AssignRequest`, `ChangeStatus`, `AddNote`) or a guarded update,
  *never* by writing the model directly. That's the rule that keeps automation
  honest: the activity log, domain events, and `first_responded_at`
  bookkeeping all stay correct because the same write-paths the agent UI uses
  run here too.

- **`RuleEngine`** — orchestrates evaluate-then-apply for the trigger and
  scheduled layers, owns the malformed-rule handling, and holds the loop guard.

### Activity-log causation — the money shot

`ActionApplier::apply()` takes a `cause` label (e.g. `"Mail Rule: Billing
keyword routing"`) and writes one activity-log entry with it before running the
effects:

```php
activity()->performedOn($request)->withProperties(['cause' => $cause])->log($cause);
```

So the request history reads *what automated the change* — sitting right next
to the field diffs the action classes log automatically. Open a request that
a rule touched and the timeline tells you a human didn't do it; a rule did, and
which one.

## 4. The loop guard

Triggers are the dangerous layer: a trigger whose action fires its own event
(a status-change rule that changes status) would re-enter the listener and
loop. The guard is a single static flag:

```php
RuleEngine::$applying = true;   // set by ActionApplier for the duration of apply()
// ...EvaluateTriggers bails immediately if this is set...
RuleEngine::$applying = false;  // reset in a finally, so a throw can't strand it
```

Because the queue runs synchronously in a request/test context, a nested event
fires *inside* the `apply()` call stack where the flag is still true — so
`EvaluateTriggers` sees it and skips. `TriggerTest` proves it: a pathological
"on any status change → change status" rule transitions the request exactly
once and stops.

This is **single-level** suppression and a named limitation: it stops a rule
re-firing *itself*, not arbitrary multi-rule chains, and it relies on the
synchronous in-stack execution that the sync queue gives. Two rules that
ping-pong across each other's events on a real async queue would not be caught
by this guard alone.

## 5. Layer by layer

### Mail Rules — at intake, before the request exists

Mail rules are special: they act *before* a request exists, so they can't go
through `ActionApplier`. Instead `MailRuleEvaluator::overridesFor()` runs the
team's active mail rules against the `InboundEmail` and returns a plain
overrides array — category, assignee, urgency — which `ProcessInboundEmail`
folds straight into the `CreateRequest` payload:

```php
$overrides = $this->applyMailRules($email, $mailbox->team_id);
$request = app(CreateRequest::class)->handle($customer, ..., [..., ...$overrides]);
```

One create, correct from birth — not created-then-edited. Rules run in
**position order** and a later rule's action wins on the same field
(last-write-wins), which is why ordering is exposed in the builder UI. Replies
to existing requests **bypass** mail rules entirely (HelpSpot behavior). The
no-rules path is byte-identical to the pre-Phase-6 pipeline, so the whole Inbox
suite stayed green when this seam was filled.

This is the seam Phase 3 left behind: `ProcessInboundEmail::applyMailRules()`
shipped as a no-op pass-through precisely so Phase 6 could fill it without
reopening the inbound pipeline.

### Triggers — on events, via a queued listener

`EvaluateTriggers` is one listener with three `handle*` methods. Laravel's
event discovery registers each by its first parameter's type (the same
mechanism that auto-registers `SendPublicReplyEmail` — no manual binding):

```
App\Events\RequestCreated        → handleRequestCreated
App\Events\RequestStatusChanged  → handleRequestStatusChanged
App\Events\NoteAdded             → handleNoteAdded
```

Each handler loads the team's active trigger rules *for that event name*,
evaluates them against the request, and applies matches — unless the loop guard
is set. `php artisan event:list` shows the bindings.

### Automation Rules — on a schedule, via `automation:run`

The time-based layer. `RunAutomationRules` (`automation:run`) scans each
active scheduled rule's team for **open** (non-closed) requests, applies the
rule to matches, and stamps `last_run_at`. It's scheduled in
`routes/console.php`:

```php
Schedule::command('automation:run')->everyFiveMinutes();
```

`composer run dev` now includes `php artisan schedule:work`, so the rules tick
during a local demo without a cron entry. **In production** you need the
standard one-line cron that calls `php artisan schedule:run` every minute.

**Idempotence is the rule author's job, by condition design.** The seeded
scheduled rule is *"older than 24h AND not urgent → set urgent."* Once a
request is escalated it's urgent, so the `is_urgent = false` clause fails and a
second run can't re-match it — repeated runs are no-ops. A rule with a
non-self-extinguishing condition (just *"older than 24h → add a note"*) would
re-fire every five minutes forever; the builder UI warns about this. The
whole-queue scan is O(open requests × rules) — fine at demo scale, a named
scaling gap.

## 6. The builder UI

`resources/views/pages/automation/⚡index.blade.php` lists rules grouped by
layer with active toggles and up/down reordering; `⚡edit.blade.php` is the
builder — name, layer (fixed after create), an event select for trigger rules,
and repeater rows of condition selects (filtered to the fields that make sense
for the layer) and action selects (the value input swaps type by action: a
category select, a user select, a status select, or free text). The whole
section is gated by the `manage automation` permission, mirrored by the sidebar
nav item. Validation rejects unknown enum values server-side (`Rule::enum`), so
a tampered `<select>` can't store something the engine can't read.

## 7. Demo script

```bash
php artisan migrate:fresh --seed
composer run dev   # serves the app + queue + schedule:work + reverb + vite
```

The seeder creates one rule per layer. Then:

1. **Mail rule** — replay the billing-shaped fixture and watch it route:
   ```bash
   php artisan mail:replay inbound-new
   ```
   (Edit the seeded "Billing keyword routing" rule's condition, or craft a
   fixture whose subject contains "invoice", to see the new request land in
   Billing instead of the mailbox default.)

2. **Trigger** — create an **urgent** request (agent UI, or the portal). The
   "Escalate new urgent requests" trigger fires on `request_created` and
   notifies the Administrator — check `sam@helpstripe.test`'s in-app bell and
   the mail log (`php artisan pail`).

3. **Scheduled** — the seeded demo data already has aged, unanswered requests.
   Run the scheduled layer by hand:
   ```bash
   php artisan automation:run
   ```
   Watch a stale, non-urgent request flip to urgent. Open it: the history shows
   **"Automation Rule: Escalate requests unanswered for 24h"** as the cause.
   Run it again — nothing changes (idempotence by design).

4. **Build your own** — open **Automation** in the sidebar, click **New rule**,
   pick a layer, add a condition and an action, save, and watch it fire.

## 8. Verify

```bash
php artisan test --compact --filter=Automation   # the six suites below
./init.sh                                         # lint + static analysis + full suite
```

- `ConditionEvaluatorTest` — the full operator × field matrix on both subject
  types, case-insensitive `contains`, `is_null`, numeric `gt`/`lt` with frozen
  time, AND semantics, empty-matches-all, cross-subject field non-match.
- `MailRuleTest` — routing, position-order last-wins, inactive ignored, set
  urgent + assign on create, reply bypasses rules.
- `TriggerTest` — urgent → notify, status-change → note, inactive silent, and
  the loop guard.
- `ScheduledRuleTest` — the 24h boundary (25h matches, 23h doesn't), run-twice
  idempotence, resolved/closed skipped, `last_run_at` stamping.
- `RuleBuilderTest` — permission gate (403 + hidden nav), build-from-scratch,
  server-side enum rejection, layer-fixed-after-create, toggle/delete,
  cross-team 404.
- `EngineEdgeCaseTest` — the engine's failure modes: a malformed rule is
  skipped (not fatal), empty actions still log a cause, a deleted target
  entity becomes a private note instead of crashing, and two scheduled rules
  can both touch one request.

## 9. Known limitations (named, not hidden)

- **Loop guard is single-level** and relies on synchronous in-stack execution
  (§4). Multi-rule cross-firing on an async queue isn't caught.
- **Scheduled scan is O(open × rules)** — fine for a demo, not for a large
  installation.
- **Conditions are AND-only**; OR is a Future Consideration.
- **Mail-rule body sanitization** is the same tag-stripped text the inbound
  pipeline produces (Phase 3) — no CSS/quoted-reply trimming.
