# HelpStripe Contract

**Created**: 2026-06-09
**Confidence Score**: 95/100
**Status**: Approved (Full scope: MVP + Full tiers)
**Supersedes**: None

## Problem Statement

A new support engineer joining HelpSpot must learn how a Laravel application works — not abstractly, but in the exact shape of the product they will support every day. Generic Laravel tutorials teach todo lists; this engineer needs requests, mailboxes, triggers, portals, and the vocabulary HelpSpot customers use on real tickets.

HelpStripe closes that gap: a teaching repository that reimplements the six pillars from HelpSpot's public tour — Shared Inbox, Ticket Management, Automation Rules, Reporting, Knowledge Base, Self-Service Portal — in simple, idiomatic Laravel 13 on this repo's existing Livewire 4 + Flux + Fortify starter. Every pillar doubles as a Laravel lesson (Eloquent relations, the mail pipeline, events and the scheduler, aggregates, broadcasting, public routes), delivered through annotated source code and a per-pillar tour doc.

Features must genuinely work and be demoable end-to-end on Laravel Herd — real email both directions via Resend, real websockets via Reverb — but are explicitly not production-ready. The bar is: follow the demo script and it works.

## Goals

1. Implement all six HelpSpot tour pillars in working, demoable form using HelpSpot's own vocabulary (Request, Filters, Knowledge Books, Responses, Mail Rules / Triggers / Automation Rules).
2. Real email round-trip via Resend: an inbound webhook creates Requests, agent replies thread correctly into the customer's inbox, and `mail:replay` reproduces the inbound flow offline.
3. Each pillar teaches distinct Laravel concepts through annotated source plus a docs/tour/ walkthrough a Laravel newcomer can follow file-by-file.
4. Use Spatie packages as the idiomatic backbone: laravel-permission (permission groups), laravel-activitylog (request history), laravel-webhook-client (Resend inbound), laravel-medialibrary (attachments), laravel-tags, laravel-sluggable.
5. Every phase keeps the repo harness green: `./init.sh` (Pint + Pest) passes and feature_list.json tracks phase status with recorded evidence.

## Success Criteria

- [ ] `./init.sh` passes (Pint clean, Pest green) at the end of every phase.
- [ ] `php artisan migrate:fresh --seed` produces a demo helpdesk: staff in permission groups, categories, mailboxes, requests in varied states, and Knowledge Base content.
- [ ] Each pillar's docs/tour/ doc can be followed start-to-finish on Laravel Herd without improvisation.
- [ ] A real email sent to the wired Resend domain creates a Request in the queue; the agent's reply lands threaded in the customer's inbox.
- [ ] A customer can submit a request on the public portal, receive a confirmation email with an access key, and check status using email + access key.
- [ ] Two browser sessions viewing the same request see each other via Reverb presence channels (collision detection).
- [ ] Automation demo: a Mail Rule routes an inbound email to a category, a Trigger fires on request creation, and a scheduled Automation Rule escalates an aged request.
- [ ] The reporting dashboard renders three reports from seeded data with Flux charts, including SLA first-response breach flagging.
- [ ] `POST /api/v1/requests` with an API token creates a request — the third intake channel.

## Scope Boundaries

### In Scope (MVP tier)

- Domain foundation: Request, Customer, Category, Mailbox models, status enum, spatie permission groups (Administrator, Help Desk Staff), factories and rich seeders. One seeded team = the HelpSpot installation.
- Ticket Management: request queue + detail Livewire UI, single assignment, public reply vs private note, full history via activitylog, saved Filters, canned Responses.
- Shared Inbox / Resend email pipeline: outbound Mailables with threading headers, inbound webhooks via laravel-webhook-client, attachments via medialibrary, `mail:replay` offline fallback.
- Self-Service Portal: public submit form, confirmation email, status lookup via email + access key (no customer accounts).
- Teaching layer: annotated source code plus one docs/tour/ doc per pillar with a step-by-step demo script.

### In Scope (Full tier — approved)

- Automation: Mail Rules (inbound pipeline), Triggers (request events), scheduled Automation Rules, with a DB-backed condition/action rule builder UI.
- Knowledge Base: Books → Chapters → Pages with drafts, slugs, portal browsing, and LIKE-based search.
- Reporting: dashboard with three core reports rendered as Flux charts, plus per-category SLA first-response targets with breach flagging.
- Collision detection via Laravel Reverb presence channels and Echo on the request detail page.
- API intake channel: `POST /api/v1/requests` with token auth and an API resource response.

### Out of Scope

- Real IMAP/SMTP mailbox polling — Resend inbound webhooks replace it.
- Multi-brand and multi-team features — one seeded team represents the single installation.
- Custom fields, forums, spam filtering, time tracking, request merging, satisfaction surveys — confirmed cut list.
- Business-hours SLA calendars — SLA stays simple wall-clock targets.
- Third-party integrations and Scout full-text search — LIKE search suffices for demos.
- Production hardening — no deliverability tuning, scaling, or security review beyond framework defaults.

### Future Considerations

- Knowledge Base deflection: suggest articles while a customer types a portal request
- Request merging (transactions + relation reparenting lesson)
- Time tracking with reporting rollups
- Scout-backed full-text search
- Custom fields
- Satisfaction surveys
- Sanctum personal access tokens for the API channel

## Execution Plan

_Pick up this contract cold and know exactly how to execute._

### Dependency Graph

```
Phase 1: Foundation & Domain Models  (blocking)
  └── Phase 2: Ticket Management  (blocking)
        ├── Phase 3: Shared Inbox & Email Pipeline
        │     ├── Phase 4: Self-Service Portal
        │     └── Phase 6: Automation Rules
        ├── Phase 7: Collision Detection   (parallel w/ 3+)
        └── Phase 8: Reporting             (parallel w/ 3+)
  └── Phase 5: Knowledge Base              (parallel after Phase 1)
```

### Execution Steps

**Strategy**: Hybrid — sequential spine (1 → 2 → 3), parallel branches (5, 7, 8 after their prereqs; 4 and 6 after 3).

1. **Phase 1** — Foundation & Domain Models _(blocking)_

   ```bash
   /ideation:execute-spec docs/ideation/helpstripe/spec-phase-1.md
   ```

2. **Phase 2** — Ticket Management _(blocking)_

   ```bash
   /ideation:execute-spec docs/ideation/helpstripe/spec-phase-2.md
   ```

3. **Phases 3, 5, 7, 8** — parallel after Phase 2 (5 only needs Phase 1; see agent team prompt, or run sequentially):

   ```bash
   /ideation:execute-spec docs/ideation/helpstripe/spec-phase-3.md
   /ideation:execute-spec docs/ideation/helpstripe/spec-phase-5.md
   /ideation:execute-spec docs/ideation/helpstripe/spec-phase-7.md
   /ideation:execute-spec docs/ideation/helpstripe/spec-phase-8.md
   ```

4. **Phases 4 & 6** — after Phase 3:

   ```bash
   /ideation:execute-spec docs/ideation/helpstripe/spec-phase-4.md
   /ideation:execute-spec docs/ideation/helpstripe/spec-phase-6.md
   ```

### Agent Team Prompt

```
Execute the HelpStripe build plan from docs/ideation/helpstripe/contract.md with an agent team.

Phase order constraints:
- Phase 1 (Foundation & Domain Models) completes first — run solo.
- Phase 2 (Ticket Management) runs next, solo (its actions/events are prerequisites for most branches).
- After Phase 2: run in parallel — Phase 3 (Shared Inbox), Phase 5 (Knowledge Base), Phase 7 (Collision Detection), Phase 8 (Reporting).
- Phase 4 (Portal) and Phase 6 (Automation) start only after Phase 3 completes.

Teammate assignments (one phase per teammate):
- Teammate A: /ideation:execute-spec docs/ideation/helpstripe/spec-phase-3.md, then spec-phase-4.md
- Teammate B: /ideation:execute-spec docs/ideation/helpstripe/spec-phase-5.md, then spec-phase-6.md (after Phase 3)
- Teammate C: /ideation:execute-spec docs/ideation/helpstripe/spec-phase-7.md
- Teammate D: /ideation:execute-spec docs/ideation/helpstripe/spec-phase-8.md

Coordinate on shared files (routes/web.php, resources/views/layouts/app/sidebar.blade.php, database/seeders/DemoSeeder.php, composer.json, .env.example, app/Models/Request.php, resources/views/pages/requests/⚡show.blade.php) — only one teammate modifies a shared file at a time; announce before touching them.

Every teammate finishes with ./init.sh green and updates feature_list.json + progress.md per the repo harness rules before reporting done.
```

---

_This contract was generated from brain dump input and approved at Full scope on 2026-06-09._
