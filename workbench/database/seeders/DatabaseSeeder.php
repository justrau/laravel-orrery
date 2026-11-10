<?php

namespace Workbench\Database\Seeders;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Seeder;
use Illuminate\Queue\Queue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Justrau\Orrery\Http\Bulk\BulkRun;
use Justrau\Orrery\Jobs\MonitorTag;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Monitoring\Phases\StuckIncidentsPhase;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;
use RuntimeException;
use Workbench\App\Jobs\BatchMemberJob;
use Workbench\App\Jobs\CampaignJob;
use Workbench\App\Jobs\DeadlineReleaseJob;
use Workbench\App\Jobs\DelayedJob;
use Workbench\App\Jobs\EncryptedJob;
use Workbench\App\Jobs\FailingJob;
use Workbench\App\Jobs\FlakyApiJob;
use Workbench\App\Jobs\LargePayloadJob;
use Workbench\App\Jobs\LongValuesJob;
use Workbench\App\Jobs\QuickJob;
use Workbench\App\Jobs\ReleasesOnceJob;
use Workbench\App\Jobs\SecretsJob;
use Workbench\App\Jobs\SilencedJob;
use Workbench\App\Jobs\TaggedJob;
use Workbench\App\Jobs\ThrottledJob;
use Workbench\App\Jobs\TimedOutJob;
use Workbench\App\Jobs\UnlimitedReleaseJob;
use Workbench\App\Notifications\WorkbenchAlert;
use Workbench\App\Support\Canaries;
use Workbench\App\Support\WorkbenchAuth;

/**
 * Seeds the Workbench database through the package's real code paths, with two exceptions.
 *
 * Jobs go through the swapped `database` queue connector, the real `queue:work` command works
 * them, `orrery:tick` counts the minutes and samples the queues, the package's own MonitorTag job monitors a
 * tag, and the package's retry service retries a failed job. States that need time to pass
 * (an expired reservation, an observed gap, an absent job) move Laravel's clock
 * (Carbon::setTestNow(), which the package's clock follows) into the past, and then act at the
 * real time. The seeder writes a package table itself only for the awaiting operation (step 16):
 * the retry service claims it, and Operations::await() marks it, as the runner would. A finished
 * bulk run (step 16b) goes through the package's bulk run engine (Http\Bulk\BulkRun).
 * workbench/fixtures.md lists the resulting counts and marks those fixtures.
 */
class DatabaseSeeder extends Seeder
{
    public const BATCH_NAME = 'Workbench import';

    public const PARTIAL_BATCH_NAME = 'Workbench partial import';

    /** The monitored tag without jobs (an empty state). */
    public const EMPTY_TAG = 'workbench:empty';

    /** The attempts of each release fixture: above the stuck thresholds (20). */
    public const RELEASE_ATTEMPTS = 21;

    /** Failures of FlakyApiJob, one failure group above the 200 members of one selection. */
    public const FLAKY_FAILURES = 210;

    /** CampaignJob pushes; every hundredth fails. */
    public const CAMPAIGN_JOBS = 300;

    /** The awaiting operation's actor. */
    public const AWAITING_ACTOR = 'operator@example.com';

    /** The queue of the finished bulk run, and its failures. */
    public const BULK_RUN_QUEUE = 'bulk-retry';

    public const BULK_RUN_JOBS = 2;

    public function run(): void
    {
        // 0. The users of the strict-auth mode (WorkbenchAuth::USERS). The default mode seeds
        //    them too, so one build serves both modes.
        WorkbenchAuth::seedUsers();
        $this->step('Seeded '.count(WorkbenchAuth::USERS).' users');

        // 0b. The first run of the stuck-incident phase, before any job: it writes the settings
        //     row incidents_since, as on an install whose scheduler runs from the start. So the
        //     unknown row of step 15 gets an incident at step 17, as the stuck rows do.
        app(StuckIncidentsPhase::class)->run(new TickBudget(Clock::monotonic() + 30));
        $this->step('Ran the first stuck-incident phase');

        // 1. Monitor the tags before any push, so the push listener records the tagged jobs.
        //    The third tag has no job: an empty tag view.
        foreach ([TaggedJob::FEATURED_TAG, CampaignJob::TAG, self::EMPTY_TAG] as $tag) {
            dispatch_sync(new MonitorTag($tag));
        }

        $this->step('Monitoring tags '.TaggedJob::FEATURED_TAG.', '.CampaignJob::TAG.' and '.self::EMPTY_TAG);

        // 2. Completed, silenced and released-once jobs on database:default.
        for ($number = 1; $number <= 60; $number++) {
            if ($number % 5 === 0) {
                TaggedJob::dispatch($number, intdiv($number, 5) % 4 + 1);
            } else {
                QuickJob::dispatch($number);
            }
        }

        for ($number = 1; $number <= 3; $number++) {
            SilencedJob::dispatch($number);
        }

        ReleasesOnceJob::dispatch();
        $this->work('database', 'default');
        $this->step('Worked 60 completed, 3 silenced and 1 released-once job');

        // 3. Failed jobs: every tenth one carries the monitored tag.
        for ($number = 1; $number <= 60; $number++) {
            FailingJob::dispatch($number, featured: $number % 10 === 0);
        }

        $this->work('database', 'default');
        $this->step('Worked 60 failing jobs');

        // 4. A batch with one failed member.
        Bus::batch([
            new BatchMemberJob(1),
            new BatchMemberJob(2),
            new BatchMemberJob(3, fails: true),
            new BatchMemberJob(4),
            new BatchMemberJob(5),
        ])->name(self::BATCH_NAME)
            ->allowFailures()
            ->onConnection('database')
            ->onQueue('default')
            ->dispatch();

        $this->work('database', 'default');
        $this->step('Worked a batch of 5 jobs with 1 failure');

        // 5. Pending jobs: delayed ones on the default queue, and jobs on a queue no worker reads.
        for ($number = 1; $number <= 5; $number++) {
            DelayedJob::dispatch($number)->delay(now()->addHour());
        }

        for ($number = 1; $number <= 5; $number++) {
            QuickJob::dispatch(100 + $number)->onQueue('idle');
        }

        $this->step('Pushed 5 delayed jobs and 5 jobs on the idle queue');

        // 6. Jobs on the second queue connection, which uses the "reports" queue by default.
        for ($number = 1; $number <= 3; $number++) {
            QuickJob::dispatch(200 + $number)->onConnection('database-secondary');
        }

        $this->work('database-secondary', 'reports');
        $this->step('Worked 3 jobs on database-secondary:reports');

        // 7. Retry the first failed FailingJob through the package's retry service, with the source
        //    cli. The cache flag lets the retried job succeed. Its original attempts failed above.
        $retried = $this->firstFailedFailingJob();

        Cache::forever(FailingJob::SUCCEED_FLAG, true);

        try {
            $result = app(RetryService::class)->retry($retried, $this->occurrenceOf($retried), 'cli');

            if ($result->result !== RetryResult::ACCEPTED) {
                throw new RuntimeException(sprintf('The retry of %s gave %s %s.', $retried, $result->result, (string) $result->code));
            }

            $this->work('database', 'default');
        } finally {
            Cache::forget(FailingJob::SUCCEED_FLAG);
        }

        $this->step('Retried failed job '.$retried);

        // 8. Failures across queues and classes: one group above 200 members, a monitored tag
        //    with 300 jobs and 3 failures, and a job that fails with the timeout exception.
        for ($number = 1; $number <= self::FLAKY_FAILURES; $number++) {
            FlakyApiJob::dispatch($number)->onQueue('emails');
        }

        $this->work('database', 'emails');

        for ($number = 1; $number <= self::CAMPAIGN_JOBS; $number++) {
            CampaignJob::dispatch($number)->onQueue('campaigns');
        }

        $this->work('database', 'campaigns');

        TimedOutJob::dispatch()->onQueue('timeouts');
        $this->work('database', 'timeouts');
        $this->step('Worked '.self::FLAKY_FAILURES.' failing FlakyApiJob, '.self::CAMPAIGN_JOBS.' CampaignJob and 1 TimedOutJob');

        // 9. The redaction canaries (Workbench\App\Support\Canaries): two failing SecretsJob
        //    with secrets in the command, the Context (visible and hidden) and a top-level key of
        //    the payload, and a pending closure that captures a secret.
        $this->secrets();
        $this->step('Worked 2 failing SecretsJob and pushed a closure with a captured secret');

        // 10. Long values: a queue of 255 characters, a name of 512 characters
        //     and a message of 64 KB.
        LongValuesJob::dispatch()->onQueue(LongValuesJob::longQueue());
        $this->work('database', LongValuesJob::longQueue());
        $this->step('Worked 1 failing LongValuesJob on a queue of 255 characters');

        // 11. A batch with completed, failed and pending members, and a secret in its options.
        Bus::batch(array_map(
            static fn (int $number): BatchMemberJob => new BatchMemberJob($number, fails: $number === 3),
            range(1, 6),
        ))->name(self::PARTIAL_BATCH_NAME)
            ->allowFailures()
            ->withOption('webhook_url', Canaries::BATCH_OPTION)
            ->onConnection('database')
            ->onQueue('partial')
            ->dispatch();

        $this->work('database', 'partial', ['--max-jobs' => 3]);
        $this->step('Worked 3 of the 6 members of the partial batch: 2 completed and 1 failed');

        // 12. Pending payloads: over 1 MB, an encrypted command (the closure is in step 9).
        //     And a queued Slack notification to an anonymous webhook route (a canary).
        LargePayloadJob::dispatch()->onQueue('payloads');
        EncryptedJob::dispatch()->onQueue('payloads');
        Notification::route('slack', Canaries::NOTIFICATION_WEBHOOK)->notify(new WorkbenchAlert);
        $this->step('Pushed a payload over 1 MB, an encrypted job and a Slack notification');

        // 13. Jobs that keep being released, each above the stuck thresholds.
        $this->releases();
        $this->step('Released ThrottledJob, UnlimitedReleaseJob and DeadlineReleaseJob '.self::RELEASE_ATTEMPTS.' times each');

        // 14. Reservations: one whose lease expired, and one reclaimed after an expired lease, so
        //     its first attempt has no observed outcome (observed_gap 1).
        $this->reservations();
        $this->step('Reserved a job whose lease expired and a reclaimed job with an observed gap');

        // 15. A job that the application deleted from the jobs table (Laravel's queue:clear):
        //     the reconciliation marks it `unknown` after its grace.
        $this->vanished();
        $this->step('Cleared a pending job outside the package: its row is unknown');

        // 16. A retry held by an operation that waits for an operator (sqlite_host_file).
        $held = $this->awaitingOperation();
        $this->step('Held failed job '.$held.' by an operation that awaits an operator');

        // 16b. A finished bulk run: two failures on database:bulk-retry, frozen and run
        //      to its end through the bulk run engine, and the retried copies worked.
        $ref = $this->bulkRun();
        $this->step('Retried '.self::BULK_RUN_JOBS.' failed jobs in the bulk run '.$ref);

        // 17. One tick: the per-minute counts (the frontier M at this minute, so the dashboard
        //     counts the seeded jobs from the mirror rows), the queue state of this minute, and
        //     the stuck incidents of the rows of steps 13 to 15, each dispatched once as
        //     StuckJobDetected.
        $this->artisan('orrery:tick');
        $this->step('Ran orrery:tick');
    }

    private function secrets(): void
    {
        $payloadKey = static fn (string $connection, ?string $queue, array $payload): array => ($payload['displayName'] ?? null) === SecretsJob::class
            ? ['workbench_api_key' => Canaries::PAYLOAD_KEY]
            : [];

        // A host application's payload hook. It stays registered; it adds the key only to
        // SecretsJob payloads.
        Queue::createPayloadUsing($payloadKey);

        Context::add('api_key', Canaries::CONTEXT_VISIBLE);
        Context::addHidden('session_token', Canaries::CONTEXT_HIDDEN);

        try {
            SecretsJob::dispatch(1)->onQueue('secrets');
            SecretsJob::dispatch(2)->onQueue('secrets');

            $captured = Canaries::CLOSURE;
            dispatch(static function () use ($captured): void {
                logger()->info('Workbench closure ran with '.strlen($captured).' characters.');
            })->onQueue('payloads');
        } finally {
            Context::forget('api_key');
            Context::forgetHidden('session_token');
        }

        $this->work('database', 'secrets');
    }

    /**
     * Each release fixture runs one attempt at a time. The throttled job releases with a delay
     * of one second, so the clock moves two seconds before each attempt, in the past of the
     * real time.
     */
    private function releases(): void
    {
        ThrottledJob::defineLimiter();
        // Inside the last minute: the first tick counts the five minutes before its own minute,
        // so the completion of the first ThrottledJob stays in them even when a minute boundary
        // passes before step 17. The 21 attempts end 18 seconds before this step's time.
        $start = Carbon::now()->subSeconds(60);

        $this->at($start, function (): void {
            // The first job takes the limiter's only slot of the hour, and completes.
            ThrottledJob::dispatch(0)->onQueue('throttled');
            $this->work('database', 'throttled', ['--once' => true]);
            ThrottledJob::dispatch(1)->onQueue('throttled');
        });

        for ($attempt = 1; $attempt <= self::RELEASE_ATTEMPTS; $attempt++) {
            $this->at($start->copy()->addSeconds(2 * $attempt), function (): void {
                $this->work('database', 'throttled', ['--once' => true]);
            });
        }

        UnlimitedReleaseJob::dispatch()->onQueue('unlimited');
        $this->work('database', 'unlimited', ['--max-jobs' => self::RELEASE_ATTEMPTS]);

        DeadlineReleaseJob::dispatch()->onQueue('deadline');
        $this->work('database', 'deadline', ['--max-jobs' => self::RELEASE_ATTEMPTS]);
    }

    /**
     * The `database` connection's retry_after is 900 seconds. A reservation 1,000 seconds ago
     * has expired; a job reserved 2,000 seconds ago and reserved again 300 seconds ago is a
     * reclaim with an observed gap. The lease of a row is its own connection's retry_after, so
     * only the first has expired: database-secondary (retry_after 90) shares the jobs table but
     * works another queue, and does not shorten the lease.
     */
    private function reservations(): void
    {
        $now = Carbon::now();
        $queue = app('queue')->connection('database');

        $this->at($now->copy()->subSeconds(2000), function () use ($queue): void {
            QuickJob::dispatch(301)->onQueue('stuck-gap');
            $this->reserve($queue, 'stuck-gap');
        });

        $this->at($now->copy()->subSeconds(300), function () use ($queue): void {
            $this->reserve($queue, 'stuck-gap');
        });

        $this->at($now->copy()->subSeconds(1000), function () use ($queue): void {
            QuickJob::dispatch(302)->onQueue('stuck-expired');
            $this->reserve($queue, 'stuck-expired');
        });
    }

    private function reserve(mixed $queue, string $name): void
    {
        if (! is_object($queue) || ! method_exists($queue, 'pop') || $queue->pop($name) === null) {
            throw new RuntimeException("No job to reserve on the queue {$name}.");
        }
    }

    /**
     * The reconciliation records the first absence, and marks the row unknown at a later check
     * at least max(retry_after, 600) seconds later. The first check runs 1,000 seconds ago.
     */
    private function vanished(): void
    {
        $reconciliation = app(Reconciliation::class);

        $this->at(Carbon::now()->subSeconds(1000), function () use ($reconciliation): void {
            QuickJob::dispatch(303)->onQueue('vanished');
            $this->artisan('queue:clear', ['connection' => 'database', '--queue' => 'vanished', '--force' => true]);
            $reconciliation->reconcileActive('database');
        });

        $reconciliation->reconcileActive('database');
    }

    /**
     * A dashboard retry whose process stopped after its claim, as a killed request leaves it,
     * then marked as the runner marks it when its next step writes the host SQLite file.
     */
    private function awaitingOperation(): string
    {
        $uuid = $this->failedFailingJob(2);
        $stopped = new RuntimeException('The Workbench seeder stops the retry after its claim.');

        Hooks::$callback = static function (string $point) use ($stopped): void {
            if ($point === RetryService::AFTER_CLAIM) {
                throw $stopped;
            }
        };

        try {
            app(RetryService::class)->retry($uuid, $this->occurrenceOf($uuid), 'dashboard', self::AWAITING_ACTOR);

            throw new RuntimeException("The retry of {$uuid} did not stop after its claim.");
        } catch (RuntimeException $error) {
            if ($error !== $stopped) {
                throw $error;
            }
        } finally {
            Hooks::$callback = null;
        }

        $mirror = Container::getInstance()->make(PackageSession::class)->mirror();
        $operation = $mirror->table(JobStore::TABLE)->where('uuid', $uuid)->value('operation_id');

        if (! is_string($operation) || ! app(Operations::class)->await($mirror, $operation, Operations::SQLITE_HOST_FILE, DatabaseClock::now($mirror))) {
            throw new RuntimeException("The retry of {$uuid} left no operation to mark as awaiting.");
        }

        return $uuid;
    }

    /**
     * Fails BULK_RUN_JOBS FailingJob on their own queue, then retries them in one bulk run as an
     * operator of the dashboard does: a signed freeze of the failed tab's filters, then every
     * page through the operation ledger (actor operator@example.com, source bulk). The cache flag
     * lets the retried copies complete in cycle 2. Returns the run's ref.
     */
    private function bulkRun(): string
    {
        for ($number = 1; $number <= self::BULK_RUN_JOBS; $number++) {
            FailingJob::dispatch(400 + $number)->onQueue(self::BULK_RUN_QUEUE);
        }

        $this->work('database', self::BULK_RUN_QUEUE);
        $runs = app(BulkRun::class);
        $frozen = $runs->freeze(BulkRun::RETRY, ['kind' => 'list', 'connection' => 'database', 'queue' => self::BULK_RUN_QUEUE])['frozen'];

        if ($frozen['total'] !== ['count' => self::BULK_RUN_JOBS, 'at_least' => false]) {
            throw new RuntimeException('The bulk run froze '.json_encode($frozen['total']).'.');
        }

        Cache::forever(FailingJob::SUCCEED_FLAG, true);

        try {
            $after = null;

            do {
                $page = $runs->run($frozen['token'], $after, null, self::AWAITING_ACTOR);

                if ($page['totals']['accepted'] !== count($page['results'])) {
                    throw new RuntimeException('The bulk run did not accept every member: '.json_encode($page['totals']));
                }

                $after = $page['next_after'];
            } while (! $page['done']);

            $this->work('database', self::BULK_RUN_QUEUE);
        } finally {
            Cache::forget(FailingJob::SUCCEED_FLAG);
        }

        return (string) $frozen['ref'];
    }

    /**
     * Run $work with Laravel's clock (and so the package's clock) at $time.
     */
    private function at(Carbon $time, Closure $work): void
    {
        // The caller's clock comes back afterwards: the real time, or a clock that a test pinned.
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($time);

        try {
            $work();
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    /**
     * Run the real `queue:work` command until the queue is empty, or with the given limits.
     *
     * @param  array<string, int|bool>  $limits
     */
    private function work(string $connection, string $queue, array $limits = []): void
    {
        $status = Artisan::call('queue:work', [
            'connection' => $connection,
            '--queue' => $queue,
            '--stop-when-empty' => true,
            '--sleep' => 0,
            '--tries' => 1,
            // The seeder can run inside a larger process (the PHPUnit suite). The default
            // 128 MB limit would stop the worker early there, with jobs still queued.
            '--memory' => 1024,
        ] + $limits);

        if ($status !== 0) {
            throw new RuntimeException(sprintf('queue:work %s --queue=%s exited with %d: %s', $connection, $queue, $status, Artisan::output()));
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function artisan(string $command, array $arguments = []): void
    {
        $status = Artisan::call($command, $arguments);

        if ($status !== 0) {
            throw new RuntimeException(sprintf('%s exited with %d: %s', $command, $status, Artisan::output()));
        }
    }

    /**
     * The uuid of the oldest failed FailingJob, read from Laravel's failed_jobs table.
     */
    private function firstFailedFailingJob(): string
    {
        return $this->failedFailingJob(1);
    }

    /**
     * The uuid of the failed FailingJob with this number, read from Laravel's failed_jobs table.
     */
    private function failedFailingJob(int $number): string
    {
        $rows = DB::connection(config('queue.failed.database'))
            ->table(config('queue.failed.table'))
            ->orderBy('id')
            ->get(['uuid', 'payload']);

        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);

            if (is_array($payload) && ($payload['displayName'] ?? null) === FailingJob::class
                && str_contains((string) ($payload['data']['command'] ?? ''), 's:6:"number";i:'.$number.';')) {
                return (string) $row->uuid;
            }
        }

        throw new RuntimeException("No failed FailingJob #{$number} found.");
    }

    /**
     * The current occurrence of a failure, read from the mirror (the seeder never writes to it).
     */
    private function occurrenceOf(string $uuid): int
    {
        $occurrence = Container::getInstance()->make(PackageSession::class)->mirror()->table(JobStore::TABLE)->where('uuid', $uuid)->value('occurrence_id');

        if (! is_numeric($occurrence)) {
            throw new RuntimeException("The failed job {$uuid} has no mirrored occurrence.");
        }

        return (int) $occurrence;
    }

    private function step(string $message): void
    {
        // Both callers, the migrate-fresh build step and WorkbenchSeederTest, run the seeder
        // through db:seed, which sets the command.
        $this->command->info('Workbench seeder: '.$message.'.');
    }
}
