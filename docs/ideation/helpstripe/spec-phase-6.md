# Implementation Spec: HelpStripe - Phase 6: Automation Rules

**Contract**: ./contract.md
**Estimated Effort**: L

## Technical Approach

All three HelpSpot automation layers, sharing one engine:

1. **Mail Rules** — evaluated inside the inbound email pipeline (the `applyMailRules()` seam Phase 3 left as a pass-through), acting on the email *before/at* request creation (set category, set urgent, assign).
2. **Triggers** — evaluated synchronously-after domain events (`RequestCreated`, `RequestStatusChanged`, `NoteAdded`) via queued listeners.
3. **Automation Rules** — time-based, evaluated by a scheduled artisan command against the whole open queue (e.g., "Active, unanswered for 24h → mark urgent + notify staff").

The shared core is a small **RuleEngine**: rules store `conditions` and `actions` as JSON arrays of constrained shapes; `ConditionEvaluator` matches a subject (an `InboundEmail` DTO or a `Request` model) and `ActionApplier` executes effects through the Phase 2 action classes — automation never writes models directly, so activity history and events stay correct (and trigger-loop protection is centralized). All three rule models share columns via a single `automation_rules` table with a `layer` enum — one table, three layers, taught explicitly as a design tradeoff vs three tables.

Laravel curriculum: queued event listeners, the scheduler (`routes/console.php`, `schedule:work`), JSON casts + value objects, service classes, and recursion guards.

## Feedback Strategy

**Inner-loop command**: `php artisan test --compact --filter=Automation`

**Playground**: Pest tests for the engine (pure logic, fastest loop); `php artisan automation:run` + `mail:replay` for end-to-end checks against seeded rules.

**Why this approach**: The engine is logic-dense — unit-style tests dominate; the UIs are thin CRUD over JSON columns.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `app/Models/AutomationRule.php` + migration + factory | layer (mail/trigger/scheduled), event (for triggers), name, is_active, position, conditions JSON, actions JSON, last_run_at |
| `app/Enums/RuleLayer.php` | Mail / Trigger / Scheduled |
| `app/Enums/ConditionField.php` | subject, body, from_email, to_mailbox, category, status, assignee, is_urgent, age_hours, hours_since_last_note |
| `app/Enums/ConditionOperator.php` | equals, not_equals, contains, gt, lt, is_null |
| `app/Enums/RuleAction.php` | set_category, assign_to, set_urgent, change_status, add_private_note, notify_user |
| `app/Support/Automation/RuleEngine.php` | evaluate + apply orchestration |
| `app/Support/Automation/ConditionEvaluator.php` | match conditions against Request or InboundEmail |
| `app/Support/Automation/ActionApplier.php` | execute actions via Phase 2 action classes |
| `app/Listeners/EvaluateTriggers.php` | Queued listener on the three domain events |
| `app/Console/Commands/RunAutomationRules.php` | `automation:run` — evaluate scheduled-layer rules |
| `app/Notifications/AutomationNotification.php` | notify_user action payload ("Rule X fired on Request #N") |
| `resources/views/pages/automation/⚡index.blade.php` | Rules list grouped by layer, active toggles, ordering |
| `resources/views/pages/automation/⚡edit.blade.php` | Rule builder: condition rows + action rows (constrained selects) |
| `tests/Feature/Automation/ConditionEvaluatorTest.php` | Operator/field matrix on both subject types |
| `tests/Feature/Automation/MailRuleTest.php` | Inbound pipeline applies mail rules in order |
| `tests/Feature/Automation/TriggerTest.php` | Events fire matching triggers; loop guard |
| `tests/Feature/Automation/ScheduledRuleTest.php` | automation:run on aged seeded data; idempotence |
| `tests/Feature/Automation/RuleBuilderTest.php` | CRUD UI validation + permission gate |
| `docs/tour/06-automation.md` | Tour doc: events/listeners, scheduler, JSON casts, service objects + demo script |

### Modified Files

| File Path | Changes |
| --- | --- |
| `app/Jobs/ProcessInboundEmail.php` | Fill the `applyMailRules()` seam with RuleEngine (mail layer) |
| `routes/console.php` | `Schedule::command('automation:run')->everyFiveMinutes()` |
| `composer.json` (`dev` script) | Add `php artisan schedule:work` to the concurrently stack |
| `routes/web.php` | `automation` routes behind `can:manage automation` |
| `resources/views/layouts/app/sidebar.blade.php` | "Automation" nav item (permission-gated) |
| `database/seeders/DemoSeeder.php` | Seed one rule per layer: "Billing emails → Billing category" (mail), "New urgent request → notify admin" (trigger), "Unanswered 24h → mark urgent" (scheduled) |

## Implementation Details

### RuleEngine core

**Overview**: `ConditionEvaluator::matches(array $conditions, Request|InboundEmail $subject): bool` — AND semantics across condition rows (HelpSpot-style; OR is a Future Consideration). Each condition: `{field, operator, value}`. Field resolution differs by subject type (e.g. `from_email` only meaningful for InboundEmail; `age_hours` only for Request) — unsupported field on a subject simply doesn't match, and the builder UI constrains choices per layer.

```php
// conditions JSON example
[{"field":"to_mailbox","operator":"equals","value":"billing@helpdesk.example.com"},
 {"field":"subject","operator":"contains","value":"invoice"}]
// actions JSON example
[{"action":"set_category","value":3},{"action":"set_urgent","value":true}]
```

**Key decisions**:
- Value objects: conditions/actions hydrate into small readonly classes (`Condition::fromArray`) — teaches casts → value object mapping without a heavy library.
- `ActionApplier` takes a `cause` label ("Mail Rule: Billing route") used for the activitylog `causedBy`/description so history shows *what automated the change* — the demo's money shot.
- **Loop guard**: ActionApplier sets a context flag (`RuleEngine::$applying`) so `EvaluateTriggers` skips events emitted by automation itself. Single-level suppression, named limitation in the doc.

**Feedback loop**:
- **Playground**: `ConditionEvaluatorTest` — pure, no DB where possible.
- **Experiment**: full operator × field matrix incl. `is_null` assignee, `gt` age_hours with frozen time, contains case-insensitivity; multi-condition AND; empty conditions array matches all (documented).
- **Check command**: `php artisan test --compact --filter=ConditionEvaluatorTest`

### Mail Rules in the pipeline

**Pattern to follow**: `app/Jobs/ProcessInboundEmail.php` seam from Phase 3

**Overview**: For new-request emails, active mail-layer rules run in position order against the `InboundEmail`; matched actions accumulate into the `CreateRequest` payload (category/urgent/assignee overrides) rather than post-hoc edits — one create, correct from birth. For matched-existing-request replies, mail rules are skipped (HelpSpot behavior).

**Feedback loop**:
- **Playground**: `MailRuleTest` with Phase 3 fixtures + seeded rules.
- **Experiment**: billing fixture → Billing category; two rules both matching apply in position order (later wins on same field); inactive rule ignored; reply email bypasses rules.
- **Check command**: `php artisan test --compact --filter=MailRuleTest`

### Triggers

**Overview**: `EvaluateTriggers` (queued) listens to `RequestCreated`, `RequestStatusChanged`, `NoteAdded`; loads active trigger-layer rules for that event name; evaluates against the Request; applies actions.

**Feedback loop**:
- **Playground**: `TriggerTest` using real events through the Phase 2 actions.
- **Experiment**: create urgent request → admin notified; status→Resolved trigger adds private note; automation-caused status change does NOT re-fire triggers (loop guard); inactive trigger silent.
- **Check command**: `php artisan test --compact --filter=TriggerTest`

### Scheduled rules + command

**Overview**: `automation:run` iterates active scheduled-layer rules; for each, evaluates open (non-Closed) requests in the team and applies actions to matches; stamps `last_run_at`. Scheduled in `routes/console.php`; `composer run dev` gains `schedule:work` so demos tick.

**Key decisions**:
- Idempotence by condition design (e.g. "unanswered 24h AND not urgent → set urgent" can't re-match once urgent) — taught explicitly; the seeded rule follows the pattern.
- Whole-queue scan is O(open requests × rules) — fine at demo scale, named scaling gap.

**Feedback loop**:
- **Playground**: `ScheduledRuleTest` with `travel()` frozen time + factory `aged` state.
- **Experiment**: 25h-old unanswered request matched, 23h not; run twice → second run no-ops; resolved requests skipped.
- **Check command**: `php artisan test --compact --filter=ScheduledRuleTest`

### Rule builder UI

**Pattern to follow**: `resources/views/pages/teams/⚡edit.blade.php` + modal patterns

**Overview**: Index lists rules grouped under Mail/Trigger/Scheduled headers with active switches and up/down ordering. Edit page: name, layer (fixed after create), event select (trigger layer only), repeater rows of condition selects (field options filtered by layer) and action selects, with value inputs swapping type by field (category select, user select, text, number). Validation rejects unknown enum values server-side.

**Feedback loop**:
- **Playground**: browser as admin; `RuleBuilderTest`.
- **Experiment**: build the three seeded rules from scratch through the UI; staff (no permission) gets 403; invalid operator-for-field rejected.
- **Check command**: `php artisan test --compact --filter=RuleBuilderTest`

### Tour doc 06

Covers: event → queued listener flow, scheduler + `schedule:work`, JSON casts → value objects, service classes, recursion guards, activitylog causation. Demo script: replay billing fixture (mail rule routes it), create an urgent request (trigger notifies), `php artisan automation:run` with a seeded aged request (escalates, history shows "Automation Rule: …"), then build a new rule in the UI and watch it fire.

## Data Model

```php
// automation_rules
$table->id();
$table->foreignId('team_id')->constrained();
$table->string('layer');                    // RuleLayer enum
$table->string('event')->nullable();        // trigger layer: which domain event
$table->string('name');
$table->boolean('is_active')->default(true);
$table->unsignedInteger('position')->default(0);
$table->json('conditions');
$table->json('actions');
$table->timestamp('last_run_at')->nullable();
$table->timestamps();
$table->index(['team_id', 'layer', 'is_active']);
```

## Testing Requirements

Per component above. **Key edge cases**: rule with empty actions (matches, does nothing, logged); deleted category referenced by an action (skip action, private note "automation action skipped"); two scheduled rules touching the same request in one run.

### Manual Testing

- [ ] Demo script start-to-finish including UI-built rule
- [ ] `schedule:work` ticks visibly in `composer run dev` output

## Error Handling

| Error Scenario | Handling Strategy |
| --- | --- |
| Action references deleted entity | Skip that action, add private note, continue rule |
| Listener job failure | failed_jobs; queue retry semantics taught in doc |
| Malformed rule JSON (hand-edited) | Value-object hydration throws → caught per-rule, rule skipped + logged, engine continues |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
| --- | --- | --- | --- | --- |
| Triggers | Infinite loop | trigger action re-fires its own event | queue meltdown | applying-context guard + test |
| Scheduled rules | Re-match every run | non-self-extinguishing conditions | repeated actions/notifications | idempotence-by-design pattern + seeded examples; doc warning in builder UI |
| Mail rules | Order sensitivity | conflicting rules on same field | surprising category | position ordering exposed in UI; later-wins documented |
| Engine | Field/subject mismatch | age_hours condition on mail layer | silent non-match confusion | builder filters fields per layer; evaluator returns false + debug log |

## Validation Commands

```bash
composer lint
php artisan test --compact --filter=Automation
composer test
./init.sh
```

## Rollout Considerations

None. Update `feature_list.json` + `progress.md` with evidence on completion.
