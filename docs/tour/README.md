# HelpStripe Tour

HelpStripe is a teaching repository: a reimplementation of the six pillars
from [HelpSpot](https://www.helpspot.com)'s public product tour in simple,
idiomatic Laravel — Livewire 4, Flux, Fortify, SQLite. Every pillar doubles
as a Laravel lesson, taught through annotated source code and one
walkthrough doc per phase.

Read the docs in order; each phase builds on the previous ones.

## Pillar → Phase → Doc Map

| # | Phase | HelpSpot Pillar(s) | Laravel Lessons | Tour Doc |
| --- | --- | --- | --- | --- |
| 1 | Foundation & Domain Models | (foundation for all pillars) | Migrations, Eloquent models & relations, enums, factories, seeders, spatie/laravel-permission, model events, namespaces | [01-foundation.md](01-foundation.md) |
| 2 | Ticket Management | Ticket Management | Livewire pages, authorization, activity log timelines, domain events, notifications | 02-ticket-management.md *(Phase 2)* |
| 3 | Shared Inbox & Email Pipeline | Shared Inbox | Mailables, inbound webhooks, queues, attachments (medialibrary), artisan commands | 03-shared-inbox.md *(Phase 3)* |
| 4 | Self-Service Portal | Self-Service Portal | Public routes, guest flows, signed access, Blade layouts | [04-portal.md](04-portal.md) |
| 5 | Knowledge Base | Knowledge Base | Nested resources, slugs (sluggable), drafts, LIKE search | 05-knowledge-base.md *(Phase 5)* |
| 6 | Automation Rules | Automation Rules (Mail Rules, Triggers, Automation Rules) | Strategy pattern, domain events, the scheduler, loop guards | 06-automation.md *(Phase 6)* |
| 7 | Collision Detection | (Ticket Management: "who's viewing") | Broadcasting, Reverb, Echo presence channels | [07-collision-detection.md](07-collision-detection.md) |
| 8 | Reporting | Reporting | Aggregate queries, query scopes (shared SLA definition), CarbonPeriod, Flux charts | [08-reporting.md](08-reporting.md) |

## Prerequisites

- PHP 8.3+, Composer, Bun ([Laravel Herd](https://herd.laravel.com) provides all of it)
- `composer setup` once, then `composer run dev` to serve the app

## The Demo Installation

Every tour doc assumes the seeded demo helpdesk:

```bash
php artisan migrate:fresh --seed
```

That builds one team — **HelpStripe Support** — with four staff
(`sam@helpstripe.test` is the Administrator; password `password` for
everyone), three categories, two mailboxes, eight customers, and forty
requests with full timelines spread over the last sixty days.

`migrate:fresh --seed` is the only supported seeding path: it rebuilds the
schema from scratch each time. Re-running the seeder on a populated
database is not supported (and not needed).
