<?php

namespace Database\Seeders;

use App\Enums\ConditionField;
use App\Enums\ConditionOperator;
use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Enums\RuleAction;
use App\Enums\RuleLayer;
use App\Enums\TeamRole;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Chapter;
use App\Models\Customer;
use App\Models\Filter;
use App\Models\KnowledgeBook;
use App\Models\Mailbox;
use App\Models\Note;
use App\Models\Page;
use App\Models\Request;
use App\Models\Response;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Builds the entire demo helpdesk installation.
 *
 * The dataset is deterministic in *shape* (fixed names, fixed counts,
 * index-derived statuses) with faker providing flavor text only — so
 * DemoSeederTest can assert exact counts without flaky randomness.
 *
 * Supported path: `php artisan migrate:fresh --seed`. The seeder is not
 * designed to be re-run against a populated database.
 *
 * Documented dataset:
 *  - 1 team ("HelpStripe Support"), all staff members
 *  - 4 staff: Sam Administrator (Administrator) + 3 Help Desk Staff
 *  - 3 categories: Billing (SLA 60m), Technical Support (SLA 240m), Sales (no SLA)
 *  - 2 mailboxes: support@ → Technical Support, billing@ → Billing
 *  - 8 customers
 *  - 40 requests over the last 60 days: 10 unassigned (every 4th),
 *    4 urgent (every 10th), mixed statuses, SLA hits and breaches,
 *    1–6 timeline notes each
 *  - 3 Responses (canned replies)
 *  - 2 shared Filters: "My Open", "Urgent Unassigned"
 *  - 2 Knowledge Books: "Getting Started" (published, 2 chapters,
 *    7 pages — 5 published incl. "Resetting Your Password", 2 drafts
 *    incl. a "password" draft twin for the search demo) and
 *    "Internal Runbook" (draft, 1 chapter, 3 pages)
 */
class DemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $team = $this->seedTeam();
        $staff = $this->seedStaff($team);
        $categories = $this->seedCategories($team);
        $mailboxes = $this->seedMailboxes($team, $categories);
        $customers = $this->seedCustomers($team);

        $this->seedRequests($team, $staff, $categories, $mailboxes, $customers);
        $this->seedResponses($team);
        $this->seedFilters($team, $staff);
        $this->seedKnowledgeBase($team);
        $this->seedAutomationRules($team, $categories, $staff);

        $this->printPortalDemoCredentials();
    }

    /**
     * Print one request's portal access credentials to the seeder output.
     *
     * The self-service portal (Phase 4) authenticates customers with their
     * email + a per-request access key — normally delivered by the
     * confirmation email. For a demo without a running mail/queue worker,
     * surfacing a known email + key here lets you exercise the email+key
     * lookup path immediately after `migrate:fresh --seed`. (The signed-link
     * path still needs the email; this is the offline fallback the spec's
     * Failure Modes table calls for.)
     */
    private function printPortalDemoCredentials(): void
    {
        if ($this->command === null) {
            return;
        }

        $request = Request::query()
            ->with('customer')
            ->whereIn('status', RequestStatus::open())
            ->orderBy('id')
            ->first();

        if ($request === null) {
            return;
        }

        $this->command->info('Portal demo — check status at /portal/lookup with:');
        $this->command->info("  Request:    #{$request->id} ({$request->subject})");
        $this->command->info("  Email:      {$request->customer->email}");
        $this->command->info("  Access key: {$request->access_key}");
    }

    /**
     * The single team representing this HelpSpot installation.
     */
    private function seedTeam(): Team
    {
        return Team::factory()->create([
            'name' => 'HelpStripe Support',
            'slug' => 'helpstripe-support',
        ]);
    }

    /**
     * Four staff members: one Administrator, three Help Desk Staff.
     *
     * UserFactory no longer auto-creates a personal team per user (the
     * starter kit behavior was disabled — HelpStripe is a single-team
     * installation), so the factory is safe to use here directly. Every
     * staff login uses the factory's default password: "password".
     *
     * Note the two authorization layers meeting here: spatie roles
     * (assignRole) govern helpdesk permissions, while the team membership
     * pivot (TeamRole) governs the starter kit's team-settings screens.
     *
     * @return Collection<int, User>
     */
    private function seedStaff(Team $team): Collection
    {
        $admin = $this->createStaffMember($team, 'Sam Administrator', 'sam@helpstripe.test', TeamRole::Owner);
        $admin->assignRole('Administrator');

        $staff = collect([
            ['name' => 'Riley Frontline', 'email' => 'riley@helpstripe.test'],
            ['name' => 'Jordan Queue', 'email' => 'jordan@helpstripe.test'],
            ['name' => 'Casey Inbox', 'email' => 'casey@helpstripe.test'],
        ])->map(function (array $attributes) use ($team) {
            $user = $this->createStaffMember($team, $attributes['name'], $attributes['email'], TeamRole::Member);
            $user->assignRole('Help Desk Staff');

            return $user;
        });

        return $staff->prepend($admin)->values();
    }

    /**
     * Create one staff user and attach them to the installation's team.
     */
    private function createStaffMember(Team $team, string $name, string $email, TeamRole $role): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'current_team_id' => $team->id,
        ]);

        $team->members()->attach($user, ['role' => $role->value]);

        return $user;
    }

    /**
     * @return Collection<int, Category>
     */
    private function seedCategories(Team $team): Collection
    {
        return collect([
            ['name' => 'Billing', 'sla_first_response_minutes' => 60],
            ['name' => 'Technical Support', 'sla_first_response_minutes' => 240],
            ['name' => 'Sales', 'sla_first_response_minutes' => null],
        ])->map(fn (array $attributes) => Category::factory()->create([
            ...$attributes,
            'team_id' => $team->id,
        ]));
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return Collection<string, Mailbox> keyed by category name
     */
    private function seedMailboxes(Team $team, Collection $categories): Collection
    {
        $byName = $categories->keyBy('name');

        // Mailbox addresses are derived from config('helpstripe.inbound_domain')
        // rather than hardcoded: when a real Resend domain is wired up (set
        // HELPSTRIPE_INBOUND_DOMAIN), the seeded support@/billing@ addresses
        // automatically match it, so outbound replies send from a verified
        // domain and inbound mail routes to the right mailbox. Defaults to
        // helpstripe.test for the offline mail:replay demo path.
        $domain = config('helpstripe.inbound_domain');

        return collect([
            'Technical Support' => ['name' => 'Support', 'local' => 'support'],
            'Billing' => ['name' => 'Billing', 'local' => 'billing'],
        ])->map(fn (array $attributes, string $categoryName) => Mailbox::factory()->create([
            'name' => $attributes['name'],
            'address' => "{$attributes['local']}@{$domain}",
            'team_id' => $team->id,
            'category_id' => $byName[$categoryName]->id,
        ]));
    }

    /**
     * @return Collection<int, Customer>
     */
    private function seedCustomers(Team $team): Collection
    {
        return Customer::factory()
            ->count(8)
            ->create(['team_id' => $team->id])
            ->values();
    }

    /**
     * Forty requests spread across the last 60 days.
     *
     * Shape is index-derived so it's stable run-to-run:
     *  - status: 1–14 active, 15–20 pending, 21–32 resolved, 33–40 closed
     *  - every 4th request unassigned (10 total)
     *  - every 10th request urgent (4 total)
     *  - category cycles Billing → Technical Support → Sales
     *  - email-category requests arrive via their mailbox; Sales via portal/agent
     *  - resolved/closed always have a first response; open requests alternate;
     *    response minutes alternate inside/outside the category's SLA target
     *
     * @param  Collection<int, User>  $staff
     * @param  Collection<int, Category>  $categories
     * @param  Collection<string, Mailbox>  $mailboxes
     * @param  Collection<int, Customer>  $customers
     */
    private function seedRequests(
        Team $team,
        Collection $staff,
        Collection $categories,
        Collection $mailboxes,
        Collection $customers,
    ): void {
        $now = CarbonImmutable::now();

        foreach (range(1, 40) as $i) {
            $category = $categories[$i % 3];
            $mailbox = $mailboxes->first(
                fn (Mailbox $candidate) => $candidate->category_id === $category->id
            );

            $status = match (true) {
                $i <= 14 => RequestStatus::Active,
                $i <= 20 => RequestStatus::Pending,
                $i <= 32 => RequestStatus::Resolved,
                default => RequestStatus::Closed,
            };

            $factory = Request::factory()
                // Spread creation over the last 60 days (but at least 2
                // days old so first responses/resolutions fit before now).
                ->aged($now->subDays(60), $now->subDays(2));

            if ($this->shouldHaveFirstResponse($i, $status)) {
                $factory = $factory->withFirstResponse(
                    $this->firstResponseMinutes($i, $category)
                );
            }

            $request = $factory->create([
                'team_id' => $team->id,
                'customer_id' => $customers[$i % 8]->id,
                'category_id' => $category->id,
                'mailbox_id' => $mailbox?->id,
                'assigned_to' => $i % 4 === 0 ? null : $staff[$i % 4]->id,
                'status' => $status,
                'source' => $mailbox !== null
                    ? RequestSource::Email
                    : ($i % 2 === 0 ? RequestSource::Portal : RequestSource::Agent),
                'is_urgent' => $i % 10 === 0,
            ]);

            if (in_array($status, [RequestStatus::Resolved, RequestStatus::Closed], true)) {
                $request->updateQuietly([
                    'resolved_at' => CarbonImmutable::parse($request->created_at)
                        ->addHours(fake()->numberBetween(4, 47)),
                ]);
            }

            $this->seedTimeline($request, $staff, $i);
        }
    }

    /**
     * Resolved/closed requests were always responded to; open requests
     * alternate so the queue shows both responded and waiting work.
     */
    private function shouldHaveFirstResponse(int $i, RequestStatus $status): bool
    {
        if (in_array($status, [RequestStatus::Resolved, RequestStatus::Closed], true)) {
            return true;
        }

        return $i % 2 === 0;
    }

    /**
     * Alternate inside/outside the category's SLA target so Phase 8 has
     * both hits and breaches to report on. No-SLA categories get a fixed
     * unremarkable response time.
     */
    private function firstResponseMinutes(int $i, Category $category): int
    {
        $target = $category->sla_first_response_minutes;

        if ($target === null) {
            return 240;
        }

        return $i % 2 === 0
            ? max(1, intdiv($target, 2)) // inside SLA
            : $target * 3;               // breach
    }

    /**
     * Three canned replies (Responses, in HelpSpot's vocabulary) for the
     * reply box picker.
     */
    private function seedResponses(Team $team): void
    {
        collect([
            [
                'name' => 'Password reset steps',
                'body' => "Hi there,\n\nYou can reset your password from the sign-in page: click \"Forgot password\", enter your email, and follow the link we send you. The link expires after 60 minutes.\n\nLet us know if it doesn't arrive!",
            ],
            [
                'name' => 'Refund processed',
                'body' => "Hi,\n\nWe've processed your refund. Depending on your bank, it can take 5–10 business days to appear on your statement.\n\nThanks for your patience!",
            ],
            [
                'name' => 'Need more information',
                'body' => "Hi,\n\nThanks for reaching out. Could you share a bit more detail so we can dig in — what you were doing, what you expected, and what happened instead? A screenshot helps too.\n\nThanks!",
            ],
        ])->each(fn (array $attributes) => Response::factory()->create([
            ...$attributes,
            'team_id' => $team->id,
        ]));
    }

    /**
     * Two shared saved Filters demonstrating the criteria vocabulary —
     * note "My Open" stores the symbolic 'me', not a user id, so it means
     * "the viewer's open requests" for whoever applies it.
     *
     * @param  Collection<int, User>  $staff
     */
    private function seedFilters(Team $team, Collection $staff): void
    {
        Filter::factory()->shared()->create([
            'team_id' => $team->id,
            'user_id' => $staff->first()->id,
            'name' => 'My Open',
            'criteria' => ['status' => RequestStatus::Active->value, 'assignee' => 'me'],
        ]);

        Filter::factory()->shared()->create([
            'team_id' => $team->id,
            'user_id' => $staff->first()->id,
            'name' => 'Urgent Unassigned',
            'criteria' => ['assignee' => 'unassigned', 'urgent' => true],
        ]);
    }

    /**
     * One automation rule per layer, exercising the whole engine end-to-end:
     *
     *  - Mail Rule: inbound mail whose subject mentions "invoice" or "refund"
     *    is filed under Billing on arrival (correct from birth).
     *  - Trigger: a new urgent request notifies the Administrator (database +
     *    mail) the moment it's created.
     *  - Automation Rule (scheduled): a request older than 24h that isn't yet
     *    urgent gets escalated by `automation:run`. The "AND not urgent" clause
     *    is the idempotence-by-design pattern — once urgent, the rule can't
     *    re-match, so repeated runs are no-ops.
     *
     * @param  Collection<int, Category>  $categories
     * @param  Collection<int, User>  $staff
     */
    private function seedAutomationRules(Team $team, Collection $categories, Collection $staff): void
    {
        $billing = $categories->firstWhere('name', 'Billing');
        $admin = $staff->first();

        AutomationRule::create([
            'team_id' => $team->id,
            'layer' => RuleLayer::Mail,
            'event' => null,
            'name' => 'Billing keyword routing',
            'is_active' => true,
            'position' => 1,
            'conditions' => [
                ['field' => ConditionField::Subject->value, 'operator' => ConditionOperator::Contains->value, 'value' => 'invoice'],
            ],
            'actions' => [
                ['action' => RuleAction::SetCategory->value, 'value' => $billing->id],
            ],
        ]);

        AutomationRule::create([
            'team_id' => $team->id,
            'layer' => RuleLayer::Trigger,
            'event' => 'request_created',
            'name' => 'Escalate new urgent requests',
            'is_active' => true,
            'position' => 1,
            'conditions' => [
                ['field' => ConditionField::IsUrgent->value, 'operator' => ConditionOperator::Equals->value, 'value' => true],
            ],
            'actions' => [
                ['action' => RuleAction::NotifyUser->value, 'value' => $admin->id],
            ],
        ]);

        AutomationRule::create([
            'team_id' => $team->id,
            'layer' => RuleLayer::Scheduled,
            'event' => null,
            'name' => 'Escalate requests unanswered for 24h',
            'is_active' => true,
            'position' => 1,
            'conditions' => [
                ['field' => ConditionField::AgeHours->value, 'operator' => ConditionOperator::GreaterThan->value, 'value' => 24],
                ['field' => ConditionField::IsUrgent->value, 'operator' => ConditionOperator::Equals->value, 'value' => false],
            ],
            'actions' => [
                ['action' => RuleAction::SetUrgent->value, 'value' => true],
            ],
        ]);
    }

    /**
     * Two Knowledge Books exercising every visibility combination the
     * portal has to handle:
     *
     *  - "Getting Started" is published: its published pages are live on
     *    the portal, its drafts are not. "Resetting Your Password" is the
     *    search demo target; "Password Requirements" is the draft twin
     *    that must NOT appear for the same query.
     *  - "Internal Runbook" is a draft book: even its *published* pages
     *    stay off the portal (book gates the subtree).
     *
     * Bodies are fixed Markdown, not faker — the portal renders them, so
     * the demo should read like a real help center.
     */
    private function seedKnowledgeBase(Team $team): void
    {
        $gettingStarted = KnowledgeBook::factory()->published()->create([
            'team_id' => $team->id,
            'name' => 'Getting Started',
            'description' => 'Everything you need for your first week.',
        ]);

        $firstSteps = Chapter::factory()->create([
            'knowledge_book_id' => $gettingStarted->id,
            'name' => 'First Steps',
        ]);

        $this->seedPages($firstSteps, [
            ['Creating Your Account', true, "## Creating your account\n\nHead to the sign-up page, enter your email, and pick a strong passphrase.\n\n- Use your work email so teammates can find you\n- You can rename your workspace later"],
            ['Resetting Your Password', true, "## Resetting your password\n\nForgot your password? No problem.\n\n1. Open the sign-in page\n2. Click **Forgot password**\n3. Follow the link we email you — it expires after 60 minutes"],
            ['Inviting Teammates', true, "## Inviting teammates\n\nOpen **Settings → Teams**, choose your team, and send an invitation by email.\n\nInvitees get a link that adds them as members."],
            ['Password Requirements', false, "## Password requirements (draft)\n\nStill drafting the password policy page — minimum length, rotation rules, and manager-approved exceptions land here."],
        ]);

        $billingBasics = Chapter::factory()->create([
            'knowledge_book_id' => $gettingStarted->id,
            'name' => 'Billing Basics',
        ]);

        $this->seedPages($billingBasics, [
            ['Reading Your Invoice', true, "## Reading your invoice\n\nInvoices arrive by email on the 1st. Line items map to your plan plus any usage overage."],
            ['Updating Payment Details', true, "## Updating payment details\n\nOpen **Billing → Payment methods** and add the new card before removing the old one — billing never lapses mid-cycle."],
            ['Refund Policy', false, "## Refund policy (draft)\n\nLegal is still reviewing the refund windows. Do not publish until sign-off."],
        ]);

        $runbook = KnowledgeBook::factory()->create([
            'team_id' => $team->id,
            'name' => 'Internal Runbook',
            'description' => 'Staff-only procedures. Not for the portal (yet).',
        ]);

        $escalations = Chapter::factory()->create([
            'knowledge_book_id' => $runbook->id,
            'name' => 'Escalations',
        ]);

        // Note "Paging the On-call" is a *published page in a draft
        // book*: the portal must still hide it — the visibility matrix
        // in PortalBrowsingTest proves it does.
        $this->seedPages($escalations, [
            ['Paging the On-call', true, "## Paging the on-call\n\nSeverity 1: page immediately. Severity 2: open a thread first, page if no answer in 15 minutes."],
            ['Incident Severity Levels', false, "## Severity levels (draft)\n\nDraft matrix of SEV1–SEV4 definitions and response targets."],
            ['Vendor Contacts', true, "## Vendor contacts\n\nEmail-provider support and datacenter NOC numbers live in the shared vault."],
        ]);
    }

    /**
     * Create pages in order. Position is assigned by Page::boot()
     * (max+1 per chapter), so array order IS display order.
     *
     * @param  list<array{0: string, 1: bool, 2: string}>  $pages  [title, published, body]
     */
    private function seedPages(Chapter $chapter, array $pages): void
    {
        foreach ($pages as [$title, $published, $body]) {
            $factory = $published
                ? Page::factory()->published()
                : Page::factory();

            $factory->create([
                'chapter_id' => $chapter->id,
                'title' => $title,
                'body' => $body,
            ]);
        }
    }

    /**
     * 1–6 timeline notes: the customer's opening message, then an
     * alternation of staff public replies, private internal notes, and
     * customer follow-ups — each timestamped after the request opened.
     *
     * @param  Collection<int, User>  $staff
     */
    private function seedTimeline(Request $request, Collection $staff, int $i): void
    {
        $createdAt = CarbonImmutable::parse($request->created_at);
        $noteCount = ($i % 6) + 1;

        foreach (range(1, $noteCount) as $position) {
            $isOpeningMessage = $position === 1;
            $isCustomerReply = $position % 3 === 0;
            $isPrivate = ! $isOpeningMessage && ! $isCustomerReply && $position % 2 === 0;

            $factory = Note::factory()->state([
                'request_id' => $request->id,
                'created_at' => $createdAt->addMinutes($position * 90),
                'updated_at' => $createdAt->addMinutes($position * 90),
                'source' => $isOpeningMessage || $isCustomerReply
                    ? $request->source
                    : RequestSource::Agent,
            ]);

            if ($isOpeningMessage || $isCustomerReply) {
                $factory = $factory->state([
                    'user_id' => null,
                    'customer_id' => $request->customer_id,
                ]);
            } else {
                $factory = $factory->state([
                    'user_id' => ($request->assigned_to ?? $staff->first()->id),
                    'customer_id' => null,
                    'is_private' => $isPrivate,
                ]);
            }

            $factory->create();
        }
    }
}
