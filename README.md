# HelpStripe

HelpStripe is a teaching repository. It's a working reimplementation of the six pillars from [HelpSpot](https://www.helpspot.com)'s public product tour, built in plain, idiomatic Laravel — so you can learn how a Laravel app fits together by reading one shaped exactly like the product you're going to support.

If you're a new support engineer (or just new to Laravel), the usual tutorial path is a todo list and a blog. This one is a helpdesk: requests, mailboxes, triggers, a customer portal, a knowledge base, reports. Every pillar is also a Laravel lesson, and there's one walkthrough doc per phase in [`docs/tour/`](docs/tour/) that explains the code as you read it.

One thing to be clear about up front: this is a demo and teaching app, not production software. The bar is "follow the demo script on [Laravel Herd](https://herd.laravel.com) and it works" — not "deploy this and take real customer email." It cuts corners that a real helpdesk wouldn't, and it says so where it does.

## What you'll learn

The repo is organized into eight phases. Each one implements a HelpSpot pillar and teaches the Laravel concepts you need to understand that pillar's code. Read the phases in order — they build on each other. The recommended starting point is the guided tour index at [`docs/tour/README.md`](docs/tour/README.md).

| # | Phase | HelpSpot pillar(s) | Laravel lessons | Tour doc |
|---|---|---|---|---|
| 1 | Foundation & Domain Models | (foundation for all pillars) | Migrations, Eloquent models & relations, enums, factories, seeders, spatie/laravel-permission, model events, namespaces | [01-foundation.md](docs/tour/01-foundation.md) |
| 2 | Ticket Management | Ticket Management | Livewire pages, authorization, activity-log timelines, domain events, notifications | [02-ticket-management.md](docs/tour/02-ticket-management.md) |
| 3 | Shared Inbox & Email Pipeline | Shared Inbox | Mailables, inbound webhooks, queues, attachments (medialibrary), artisan commands | [03-shared-inbox.md](docs/tour/03-shared-inbox.md) |
| 4 | Self-Service Portal | Self-Service Portal | Public routes, guest flows, signed access, Blade layouts | [04-portal.md](docs/tour/04-portal.md) |
| 5 | Knowledge Base | Knowledge Base | Nested resources, slugs (sluggable), drafts, LIKE search | [05-knowledge-base.md](docs/tour/05-knowledge-base.md) |
| 6 | Automation Rules | Automation Rules (Mail Rules, Triggers, Automation Rules) | JSON casts → value objects, queued event listeners, the scheduler, loop guards | [06-automation.md](docs/tour/06-automation.md) |
| 7 | Collision Detection | Ticket Management ("who's viewing") | Broadcasting, Reverb, Echo presence channels | [07-collision-detection.md](docs/tour/07-collision-detection.md) |
| 8 | Reporting | Reporting | Aggregate queries, query scopes (shared SLA definition), CarbonPeriod, Flux charts | [08-reporting.md](docs/tour/08-reporting.md) |

The deeper design rationale — the original contract and the per-phase specs — lives in [`docs/ideation/helpstripe/`](docs/ideation/helpstripe/) if you want to see why things are shaped the way they are. You don't need it to follow the tour.

## The stack

Laravel 13 on PHP 8.3+, with the pieces a helpdesk actually needs:

- **Livewire 4 + Flux** for the UI, **Fortify** for auth — server-rendered, no separate frontend app.
- **SQLite** by default (zero setup), **Reverb + Echo** for websockets, **Resend** for email in both directions.
- Spatie packages as the idiomatic backbone: `laravel-permission` (roles), `laravel-activitylog` (timelines), `laravel-webhook-client` (Resend inbound), `laravel-medialibrary` (attachments), `laravel-tags`, and `laravel-sluggable`.
- Tooling: Pint (lint), PHPStan/Larastan (static analysis), Pest 4 (tests), Bun (JS), and Laravel Boost.

## Setup

You need PHP 8.3+, Composer, and Bun. [Laravel Herd](https://herd.laravel.com) bundles all three, which is the path the tour assumes.

```bash
composer setup
```

`composer setup` installs PHP and JS dependencies, copies `.env`, generates an app key, runs the migrations, and builds the frontend assets. Once that finishes, build the demo helpdesk:

```bash
php artisan migrate:fresh --seed
```

This is the only supported seeding path — it rebuilds the schema from scratch every time it runs. Re-running the seeder against a database that already has data isn't supported (and isn't needed). See ["The demo installation"](#the-demo-installation) below for what you get.

Then serve everything at once:

```bash
composer run dev
```

That runs five processes concurrently under named labels: `server` (`php artisan serve`), `queue` (`queue:listen`), `logs` (`pail`), `schedule` (`schedule:work`, automation), and `vite` (`bun run dev`). The automation feature needs the queue and scheduler running, which is why they're in here.

The websocket server (Reverb) is **not** in this stack. [Laravel Herd](https://herd.laravel.com) runs a managed Reverb on port 8080 automatically, so starting a second one here would collide. If you're not on Herd, run `php artisan reverb:start` in its own terminal — see ["Optional: live email and websockets"](#optional-live-email-and-websockets).

## Commands worth knowing

```bash
./init.sh             # baseline check: composer install + lint + the full test suite
composer test         # config:clear, Pint check, PHPStan, then php artisan test
composer lint         # Pint autofix
composer types:check  # PHPStan
```

The test suite currently runs 344 tests / 1210 assertions, all green.

Two artisan commands are central to how the app is meant to be demoed and tested:

```bash
php artisan mail:replay      # replays recorded inbound-email fixtures through the live pipeline, fully OFFLINE
php artisan automation:run   # scans the open queue and applies scheduled Automation Rules
```

`mail:replay` is the offline fallback for the email pipeline when Resend isn't wired up — you get the full inbound flow (email becomes a Request) without any external setup. `automation:run` also fires every 5 minutes via the scheduler when `composer run dev` is going.

## The demo installation

After `migrate:fresh --seed` you get one team — **HelpStripe Support** — with:

- Four staff. `sam@helpstripe.test` is the Administrator; the password is `password` for everyone.
- Three categories, two mailboxes, eight customers.
- Forty requests with full timelines spread over the last sixty days — 10 unassigned, 4 urgent.
- Seeded knowledge-base books, chapters, and pages; canned Responses; saved Filters.
- One automation rule per layer: a Mail Rule, a Trigger, and a scheduled Automation Rule.

The portal demo prints customer access keys to the seeder output, so watch the console when you seed.

## Optional: live email and websockets

Most of the app runs, tests, and demos with no external services. The inbound email pipeline is covered offline by `mail:replay`, and the broadcast/auth matrix is covered by Pest. You only need the setup below if you want a live round-trip.

**Live email (Resend).** For a real round-trip — an actual inbound email creates a Request, and an agent reply threads back to the customer — you need a one-time external setup: a verified domain, inbound MX records, and a webhook secret. The checklist is in [docs/tour/03-shared-inbox.md](docs/tour/03-shared-inbox.md).

**Live collision detection (Reverb).** To see "who's viewing this request" update live across two browsers, you need the Reverb websocket server running and the `REVERB_*` / `VITE_REVERB_*` env vars set (placeholders are in `.env.example`). On Laravel Herd, enable Reverb and it runs automatically on port 8080 — point the client vars at it (`REVERB_HOST=reverb.herd.test`, `REVERB_PORT=443`, `REVERB_SCHEME=https`). Off Herd, run `php artisan reverb:start` in its own terminal and use the localhost defaults. Either way, do **not** add `reverb:start` back into `composer run dev` on Herd — two servers can't share port 8080. The two-browser walkthrough is in [docs/tour/07-collision-detection.md](docs/tour/07-collision-detection.md).

## A teaching note about the code

The comments in the source are intentional — this repo wants them. They're part of the lesson.

There's also one deliberate naming collision worth flagging before it confuses you: the Eloquent model is `App\Models\Request`, which collides on purpose with `Illuminate\Http\Request`. That's there to teach how PHP namespaces and `use` statements actually resolve. You'll see it explained in the foundation tour doc.

## License

This project doesn't currently declare a license of its own. It's a teaching reimplementation that reproduces the structure of HelpSpot's product tour; treat it as a learning resource rather than something to ship.
