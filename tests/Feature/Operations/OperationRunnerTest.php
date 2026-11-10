<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Closure;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Retry\OperationContext;
use Justrau\Orrery\Retry\OperationHandler;
use Justrau\Orrery\Retry\OperationRunner;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\OperationStep;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\TestClock;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;
use stdClass;

/**
 * The generic part of the operation runner:
 * selection, cursor, pass boundary, deferral,
 * backoff and the stop rule, with a test handler. The retry and forget
 * handlers and their acceptance tests are tested elsewhere. Kind: new behaviour.
 */
final class OperationRunnerTest extends TestCase
{
    /** @var list<string> the ids that the test handler stepped, in order */
    private array $stepped = [];

    private ?Closure $decide = null;

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $handler = new class($test) implements OperationHandler
        {
            public function __construct(private readonly OperationRunnerTest $test) {}

            public function action(): string
            {
                return Operations::FORGET;
            }

            public function step(stdClass $operation, OperationContext $context): OperationStep
            {
                return $this->test->stepOf($operation, $context);
            }
        };

        $this->runner()->handle($handler);
    }

    /**
     * @internal the test handler's step
     */
    public function stepOf(stdClass $operation, OperationContext $context): OperationStep
    {
        $this->stepped[] = (string) $operation->id;

        return $this->decide === null ? OperationStep::decided() : ($this->decide)($operation, $context);
    }

    /**
     * Rejects: a selection in another order than (next_check_at, id), and one that takes rows that are not
     * due, not active, or that wait for an operator (sqlite_host_file), or skips a re-admitted read-blocked row.
     */
    public function test_selects_due_operations_in_key_order(): void
    {
        $now = $this->mirrorNow();
        $b = $this->operation($now - 50, 'b');
        $a = $this->operation($now - 50, 'a');
        $c = $this->operation($now - 100, 'c');
        $this->operation($now + 100, 'd');
        $this->operation($now - 10, 'e', ['state' => Operations::DONE]);
        $this->operation($now - 10, 'f', ['awaiting_since' => Clock::toDecimal($now - 500), 'awaiting_reason' => Operations::SQLITE_HOST_FILE]);
        $g = $this->operation($now - 5, 'g', ['awaiting_since' => Clock::toDecimal($now - 500), 'awaiting_reason' => Operations::SQLITE_READS_NOT_ALLOWED]);

        $report = $this->runOnce();

        self::assertSame([$c, $a, $b, $g], $this->stepped);
        self::assertSame(4, $report['inspected']);
        self::assertSame(OperationRunner::STOP_PASS_END, $report['stopped']);
    }

    /**
     * Rejects: a run without the 100-row limit, a cursor that is not persisted (the next run starts from the
     * oldest row again, so later rows starve), and a pass that never ends.
     */
    public function test_cursor_resumes_and_a_new_pass_starts_at_the_boundary(): void
    {
        // The run's 5 s cap (OperationRunner::CAP_SECONDS) must not cut the 100 rows on a loaded machine.
        Clock::freezeMonotonicForTesting();
        $now = $this->mirrorNow();
        $ids = [];

        foreach (range(1, 130) as $i) {
            $ids[] = $this->operation($now - 1000 + $i, sprintf('%03d', $i));
        }

        // The handler decides nothing and moves nothing, so every row stays due.
        $this->decide = static fn (): OperationStep => OperationStep::decided();

        $first = $this->runOnce();
        self::assertSame(100, $first['inspected']);
        self::assertSame(OperationRunner::STOP_LIMIT, $first['stopped']);
        self::assertSame(array_slice($ids, 0, 100), $this->stepped);

        $this->stepped = [];
        $second = $this->runOnce();
        self::assertSame(array_slice($ids, 100), $this->stepped, 'The second run continues after the cursor.');
        self::assertSame(OperationRunner::STOP_PASS_END, $second['stopped']);

        $this->stepped = [];
        $this->runOnce();
        self::assertSame(array_slice($ids, 0, 100), $this->stepped, 'A new pass starts from the beginning.');
    }

    /**
     * Rejects: a cursor without a pass boundary, so operations that become due during a pass, ahead of the
     * cursor, delay the operations behind it without bound.
     */
    public function test_pass_boundary_excludes_arrivals(): void
    {
        $now = $this->mirrorNow();
        $first = $this->operation($now - 100, 'a');
        $second = $this->operation($now - 90, 'b');
        $third = $this->operation($now - 80, 'c');

        // The first step costs the whole cap: the run stops after one row.
        $this->decide = static function (): OperationStep {
            Clock::advanceMonotonicForTesting(OperationRunner::CAP_SECONDS + 1);

            return OperationStep::decided();
        };
        $report = $this->runOnce();
        self::assertSame([$first], $this->stepped);
        self::assertSame(OperationRunner::STOP_CAP, $report['stopped']);

        // An arrival that is due, with a key after the boundary.
        $arrival = $this->operation($now - 60, 'z');
        $this->decide = null;
        $this->stepped = [];
        $this->runOnce();
        self::assertSame([$second, $third], $this->stepped, 'The pass ends at its boundary.');

        $this->stepped = [];
        $this->runOnce();
        self::assertContains($arrival, $this->stepped, 'The next pass takes the arrival.');
    }

    /**
     * Rejects: a run that goes on after a blocking failure, a deferral that is not persisted (a later run blocks
     * on the same connection again), and a deferral without its growing backoff.
     */
    public function test_blocked_step_defers_the_connection_and_stops_the_run(): void
    {
        // Another database connection name (the same database): the deferral is per connection name.
        $this->app['config']->set('database.connections.testbench_elsewhere', $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY));
        $this->app['config']->set('queue.connections.elsewhere', ['driver' => 'database', 'connection' => 'testbench_elsewhere', 'table' => 'jobs', 'queue' => 'default']);
        $now = $this->mirrorNow();
        // Retries: a queue deferral holds only operations that act on the queue (a forget acts on its
        // failed-record connection). Their deadlines have passed, so the handler steps them.
        $this->handleRetriesToo();
        $a = $this->operation($now - 30, 'a', ['action' => Operations::RETRY]);
        $b = $this->operation($now - 20, 'b', ['action' => Operations::RETRY]);
        $c = $this->operation($now - 10, 'c', ['queue_connection' => 'elsewhere', 'action' => Operations::RETRY]);

        $this->decide = static fn (stdClass $operation): OperationStep => $operation->queue_connection === 'database'
            ? OperationStep::blocked(TestEnvironment::PRIMARY, 'queue_unreachable', 'connection refused')
            : OperationStep::decided();

        $report = $this->runOnce();
        self::assertSame([$a], $this->stepped, 'The run stops at its first blocking failure.');
        self::assertSame(TestEnvironment::PRIMARY, $report['deferred']);
        self::assertSame(OperationRunner::STOP_BLOCKED, $report['stopped']);
        $row = $this->row($a);
        self::assertSame(1, (int) $row->checks);
        self::assertSame('queue_unreachable', $row->error_code);
        self::assertEqualsWithDelta($now + 60, (float) $row->next_check_at, 30);
        self::assertSame(1, $this->deferral(TestEnvironment::PRIMARY)['failures']);

        // While deferred, the connection's operations are not selected; the others are.
        $this->stepped = [];
        $this->runOnce();
        self::assertSame([$c], $this->stepped);

        // After the deferral, the second consecutive failure defers for 5 minutes.
        $this->shiftMirror(120.0);
        $this->stepped = [];
        $this->runOnce();
        self::assertSame([$b], $this->stepped, 'b was due all along; a is not due again before its own backoff.');
        $deferral = $this->deferral(TestEnvironment::PRIMARY);
        self::assertSame(2, $deferral['failures']);
        self::assertEqualsWithDelta($now + 120 + 300, $deferral['until'], 30);

        // The connection recovers: its deferral row goes, so the next failure starts at the first step again.
        $this->shiftMirror(7200.0);
        $this->decide = null;
        $this->stepped = [];
        $this->runOnce();
        self::assertContains($a, $this->stepped);
        self::assertNull($this->mirror()->table('orrery_settings')->where('key', OperationRunner::DEFER_PREFIX.TestEnvironment::PRIMARY)->value('value'));
    }

    /**
     * Rejects: a constant backoff, and one that does not grow to the hourly step.
     */
    public function test_undecided_checks_back_off_by_their_count(): void
    {
        $now = $this->mirrorNow();
        $this->decide = static fn (): OperationStep => OperationStep::undecided('no_decision');
        $expected = [1 => 60, 2 => 300, 3 => 900, 4 => 3600, 5 => 3600];

        foreach ($expected as $checks => $backoff) {
            $id = $this->operation($now - 10, 'x'.$checks, ['checks' => $checks - 1]);
            $this->runOnce();
            $row = $this->row($id);

            self::assertSame($checks, (int) $row->checks);
            self::assertEqualsWithDelta($now + $backoff, (float) $row->next_check_at, 30, "the backoff after check {$checks}");
            self::assertSame('no_decision', $row->error_code);
        }
    }

    /**
     * Rejects: an awaiting read-blocked operation that the runner re-checks without a count and a backoff (a
     * still-refused row would be re-checked at every run), and a host-file wait that counts checks.
     */
    public function test_awaiting_steps_mark_the_operation(): void
    {
        $now = $this->mirrorNow();
        $reads = $this->operation($now - 10, 'r');
        $write = $this->operation($now - 9, 'w');
        $this->decide = static fn (stdClass $operation): OperationStep => OperationStep::awaiting(
            $operation->id === $reads ? Operations::SQLITE_READS_NOT_ALLOWED : Operations::SQLITE_HOST_FILE,
        );

        $this->runOnce();

        $row = $this->row($reads);
        self::assertSame(Operations::SQLITE_READS_NOT_ALLOWED, $row->awaiting_reason);
        self::assertSame(1, (int) $row->checks);
        self::assertEqualsWithDelta($now + 60, (float) $row->next_check_at, 30);

        $row = $this->row($write);
        self::assertSame(Operations::SQLITE_HOST_FILE, $row->awaiting_reason);
        self::assertSame(0, (int) $row->checks);
        self::assertNotNull($row->awaiting_since);

        // The host-file wait is operator-only: never selected again.
        $this->shiftMirror(7200.0);
        $this->stepped = [];
        $this->runOnce();
        self::assertSame([$reads], $this->stepped);
    }

    /**
     * Rejects: a read-blocked operation that stays awaiting after its step ran: a later or undecided
     * step means the reads were allowed, so the awaiting fields go and the abort's error_code stays.
     */
    public function test_a_read_blocked_operation_that_runs_waits_no_more(): void
    {
        $now = $this->mirrorNow();
        $awaiting = ['awaiting_since' => Clock::toDecimal($now - 500), 'awaiting_reason' => Operations::SQLITE_READS_NOT_ALLOWED, 'error_code' => 'record_delete_failed'];
        $later = $this->operation($now - 10, 'l', $awaiting);
        $undecided = $this->operation($now - 9, 'u', $awaiting);
        $host = $this->operation($now - 8, 'h', ['awaiting_since' => Clock::toDecimal($now - 500), 'awaiting_reason' => Operations::SQLITE_READS_NOT_ALLOWED]);
        $this->decide = static fn (stdClass $operation): OperationStep => match ($operation->id) {
            $later => OperationStep::later($now + 100),
            $undecided => OperationStep::undecided(),
            default => OperationStep::awaiting(Operations::SQLITE_READS_NOT_ALLOWED),
        };

        $this->runOnce();

        foreach ([$later, $undecided] as $id) {
            $row = $this->row($id);
            self::assertNull($row->awaiting_since, "{$id} waits no more.");
            self::assertNull($row->awaiting_reason);
            self::assertSame('record_delete_failed', $row->error_code, 'The abort\'s code stays.');
        }

        self::assertSame(Operations::SQLITE_READS_NOT_ALLOWED, $this->row($host)->awaiting_reason, 'A step that is still refused keeps waiting.');
    }

    /**
     * Rejects: a throwing handler that stops the run or leaves its operation due at once.
     */
    public function test_a_throwing_step_is_an_undecided_check(): void
    {
        $now = $this->mirrorNow();
        $bad = $this->operation($now - 20, 'a');
        $good = $this->operation($now - 10, 'b');
        $this->decide = static function (stdClass $operation) use ($bad): OperationStep {
            if ($operation->id === $bad) {
                throw new RuntimeException('a broken step');
            }

            return OperationStep::decided();
        };

        $report = $this->runOnce();

        self::assertSame([$bad, $good], $this->stepped);
        self::assertSame(1, $report['decided']);
        self::assertSame('check_failed', $this->row($bad)->error_code);
        self::assertSame(1, (int) $this->row($bad)->checks);
    }

    /**
     * Rejects: a claimed retry checked before its deadline (a resolution would cancel a live publisher), a
     * reschedule that counts a check, and a deadline read in the mirror's clock instead of the queue's.
     */
    public function test_claimed_retry_before_its_queue_deadline_waits_without_a_check(): void
    {
        $now = $this->mirrorNow();
        $queueNow = DatabaseClock::now($this->app->make(PackageSession::class)->forQueue('database'));
        $retry = $this->operation($now - 10, 'r', ['action' => Operations::RETRY, 'deadline' => Clock::toDecimal($queueNow + 40)]);
        $retries = [];
        $this->runner()->handle(new class($retries) implements OperationHandler
        {
            /** @param  list<string>  $retries */
            public function __construct(private array &$retries) {}

            public function action(): string
            {
                return Operations::RETRY;
            }

            public function step(stdClass $operation, OperationContext $context): OperationStep
            {
                $this->retries[] = (string) $operation->id;

                return OperationStep::decided();
            }
        });

        $this->runOnce();

        self::assertSame([], $retries, 'The handler is not called before the deadline.');
        $row = $this->row($retry);
        self::assertSame(0, (int) $row->checks);
        self::assertEqualsWithDelta($now + 40, (float) $row->next_check_at, 5);

        // The queue database's clock passes the deadline: the handler decides.
        TestClock::shift(TestEnvironment::PRIMARY, 100.0);
        $this->mirror()->table(Operations::TABLE)->where('id', $retry)->update(['next_check_at' => Clock::toDecimal($this->mirrorNow() - 1)]);
        $this->runOnce();
        self::assertSame([$retry], $retries);
    }

    /**
     * Steps retry operations with the test handler too.
     */
    private function handleRetriesToo(): void
    {
        $test = $this;
        $this->runner()->handle(new class($test) implements OperationHandler
        {
            public function __construct(private readonly OperationRunnerTest $test) {}

            public function action(): string
            {
                return Operations::RETRY;
            }

            public function step(stdClass $operation, OperationContext $context): OperationStep
            {
                return $this->test->stepOf($operation, $context);
            }
        });
    }

    private function runner(): OperationRunner
    {
        return $this->app->make(OperationRunner::class);
    }

    /**
     * @return array{inspected: int, decided: int, deferred: string|null, stopped: string}
     */
    private function runOnce(): array
    {
        return $this->runner()->runOnce(new TickBudget(Clock::monotonic() + 30));
    }

    private function mirrorNow(): float
    {
        return DatabaseClock::now($this->app->make(PackageSession::class)->mirror());
    }

    /**
     * Moves the mirror's clock (and, when the mirror is the queue database's session, the queue's too).
     */
    private function shiftMirror(float $seconds): void
    {
        $name = (string) $this->app->make(PackageSession::class)->mirror()->getName();
        TestClock::shift(str_starts_with($name, PackageSession::NAME_PREFIX) ? substr($name, strlen(PackageSession::NAME_PREFIX)) : $name, $seconds);
    }

    /**
     * A forget operation (the test handler's action) that is due at $at; $suffix orders equal times by id.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function operation(float $at, string $suffix, array $overrides = []): string
    {
        $id = str_pad(bin2hex($suffix), 32, '0', STR_PAD_LEFT);
        $id = substr(str_repeat('0', 32).$id, -32);
        $this->app->make(Operations::class)->insertClaimed($this->mirror(), [
            'id' => $id,
            'action' => Operations::FORGET,
            'mode' => Operations::NONE,
            'job_uuid' => '0f5a3c1e-6b1d-4e57-9a3e-'.str_pad(substr(bin2hex($suffix), 0, 12), 12, '0', STR_PAD_LEFT),
            'occurrence_id' => 1,
            'queue_connection' => 'database',
            'queue' => 'default',
            'queue_fingerprint' => str_repeat('a', 64),
            'deadline' => $at,
            'next_check_at' => $at,
            'source' => 'cli',
        ]);

        $extra = array_diff_key($overrides, ['id' => true]);

        if ($extra !== []) {
            $this->mirror()->table(Operations::TABLE)->where('id', $id)->update($extra);
        }

        return $id;
    }

    private function row(string $id): object
    {
        $row = $this->app->make(Operations::class)->find($this->mirror(), $id);
        self::assertNotNull($row);

        return $row;
    }

    /**
     * @return array{until: float, failures: int}
     */
    private function deferral(string $connection): array
    {
        $value = json_decode((string) $this->mirror()->table('orrery_settings')->where('key', OperationRunner::DEFER_PREFIX.$connection)->value('value'), true);
        self::assertIsArray($value);

        return ['until' => (float) $value['until'], 'failures' => (int) $value['failures']];
    }
}
