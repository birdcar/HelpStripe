<?php

namespace App\Console\Commands;

use App\Jobs\ProcessInboundEmail;
use App\Models\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Spatie\WebhookClient\Models\WebhookCall;

/**
 * Feed a recorded Resend payload through the live inbound pipeline.
 *
 * The whole email path is demoable without a Resend account, a verified
 * domain, or an internet tunnel: a fixture under tests/Fixtures/resend/
 * bundles the webhook body, the email content the job would fetch, and the
 * attachment listing. This command fakes those two HTTP fetches, stores a
 * WebhookCall exactly like the package's controller would, and runs
 * ProcessInboundEmail synchronously — so a replay exercises matching,
 * threading, reopening, attachments, and the confirmation mail through the
 * same code a live email hits.
 *
 *     php artisan mail:replay              # replays every fixture
 *     php artisan mail:replay inbound-new  # replays one
 */
class ReplayInboundMail extends Command
{
    protected $signature = 'mail:replay {fixture? : Fixture name without .json (omit to replay all)}';

    protected $description = 'Replay a recorded Resend inbound email through the processing pipeline';

    public function handle(): int
    {
        $fixtures = $this->fixturesToReplay();

        if ($fixtures === []) {
            $this->error('No fixtures found in tests/Fixtures/resend/.');

            return self::FAILURE;
        }

        foreach ($fixtures as $name) {
            $this->replay($name);
        }

        return self::SUCCESS;
    }

    /**
     * Resolve which fixtures to replay: the named one, or all of them.
     *
     * @return list<string>
     */
    protected function fixturesToReplay(): array
    {
        /** @var string|null $argument */
        $argument = $this->argument('fixture');

        if ($argument !== null) {
            return [$argument];
        }

        $paths = glob(base_path('tests/Fixtures/resend/*.json')) ?: [];
        $names = array_map(fn (string $path) => basename($path, '.json'), $paths);
        sort($names);

        return $names;
    }

    /**
     * Replay a single fixture and report the resulting request.
     */
    protected function replay(string $name): void
    {
        $path = base_path("tests/Fixtures/resend/{$name}.json");

        if (! is_file($path)) {
            $this->error("Fixture not found: {$name}");

            return;
        }

        /** @var array{webhook: array<string, mixed>, email: array<string, mixed>, attachments: array<string, mixed>} $fixture */
        $fixture = json_decode((string) file_get_contents($path), associative: true);

        $emailId = $fixture['webhook']['data']['email_id'] ?? null;

        // Fake the Resend reads the job performs: the email content, the
        // attachment listing, and each attachment binary's short-lived CDN
        // download URL (read straight from the fixture's listing). This is
        // what makes a replay fully offline — no Resend account, no network.
        // The attachments route is registered before the content route
        // because Http::fake matches in declaration order and the content
        // URL is a prefix of it.
        $stubs = ["api.resend.com/emails/receiving/{$emailId}/attachments" => Http::response($fixture['attachments'])];

        foreach ($fixture['attachments']['data'] ?? [] as $attachment) {
            if (isset($attachment['download_url'])) {
                $stubs[$attachment['download_url']] = Http::response('%PDF-1.4 replayed attachment placeholder');
            }
        }

        $stubs["api.resend.com/emails/receiving/{$emailId}"] = Http::response($fixture['email']);

        Http::fake($stubs);

        $webhookCall = WebhookCall::create([
            'name' => 'resend',
            'url' => 'mail:replay',
            'headers' => [],
            'payload' => $fixture['webhook'],
        ]);

        $this->info("Replaying {$name}…");

        (new ProcessInboundEmail($webhookCall))->handle();

        $request = Request::query()->latest('id')->first();

        if ($request === null) {
            $this->warn('  No request was created or matched (the email may have been deduplicated).');

            return;
        }

        $this->line("  Request #{$request->id}: {$request->subject} [{$request->status->label()}]");
        $this->line('  '.route('requests.show', [
            'current_team' => $request->team->slug,
            'request' => $request->id,
        ]));
    }
}
