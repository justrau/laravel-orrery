<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\Publication;
use Justrau\Orrery\Storage\Clock;

/**
 * orrery:resolve: the operator's resolve from the console.
 * Kind: new behaviour.
 */
final class ResolveCommandTest extends RetryProtocolTestCase
{
    /**
     * Rejects: a resolve that acts while a fingerprint differs, or that reports success for it: a claimed retry
     * whose queue target changed exits 1 and stays claimed with target_changed.
     */
    public function test_target_changed_exits_one(): void
    {
        $id = $this->ledgerOperation(Operations::CLAIMED, str_repeat('0', 64));

        self::assertSame(1, Artisan::call('orrery:resolve', ['id' => $id]));
        self::assertStringContainsString('target changed', Artisan::output());
        self::assertSame([Operations::CLAIMED, 'target_changed'], [$this->operation($id)->state, $this->operation($id)->error_code]);
    }

    /**
     * Rejects: --all that resolves only one operation, or operations that wait for nobody: both awaiting
     * operations end; an operation that is not awaiting is left to the runner.
     */
    public function test_all_resolves_every_awaiting_operation(): void
    {
        $fingerprint = $this->app->make(Publication::class)->queueFingerprint('database');
        $a = $this->ledgerOperation(Operations::PUBLISHED, $fingerprint, true);
        $b = $this->ledgerOperation(Operations::PUBLISHED, $fingerprint, true);
        $c = $this->ledgerOperation(Operations::PUBLISHED, $fingerprint, false);

        self::assertSame(0, Artisan::call('orrery:resolve', ['--all' => true]));

        self::assertSame(Operations::DONE, $this->operation($a)->state);
        self::assertSame(Operations::DONE, $this->operation($b)->state);
        self::assertSame(Operations::PUBLISHED, $this->operation($c)->state);
        self::assertSame(0, Artisan::call('orrery:resolve', ['--all' => true]));
        self::assertStringContainsString('No operation waits for an operator.', Artisan::output());
    }

    /**
     * Rejects: an exit 0 for an operation that stays open: a claimed retry before its deadline only
     * gets its next check (`later`), so the requested work did not happen. It exits 1, says that the
     * operation is still open, and the operation stays claimed.
     * Still passes: a command that exits 1 without the explanation for every open step kind.
     */
    public function test_an_operation_that_stays_open_exits_one(): void
    {
        $fingerprint = $this->app->make(Publication::class)->queueFingerprint('database');
        $id = $this->ledgerOperation(Operations::CLAIMED, $fingerprint, false, Clock::now() + 3600);

        self::assertSame(1, Artisan::call('orrery:resolve', ['id' => $id]));
        self::assertStringContainsString('still open', Artisan::output());
        self::assertSame(Operations::CLAIMED, $this->operation($id)->state);
    }

    /**
     * Rejects: a command that guesses without an id, or that accepts an id and --all together, or an unknown id.
     */
    public function test_usage_errors_and_an_unknown_id(): void
    {
        self::assertSame(2, Artisan::call('orrery:resolve'));
        self::assertSame(2, Artisan::call('orrery:resolve', ['id' => Operations::newId(), '--all' => true]));
        self::assertSame(1, Artisan::call('orrery:resolve', ['id' => Operations::newId()]));
        self::assertStringContainsString('No operation', Artisan::output());
        self::assertSame(1, Artisan::call('orrery:resolve', ['id' => 'not-an-id']));
    }

    private function ledgerOperation(string $state, string $fingerprint, bool $awaiting = false, float $deadline = 1000.0): string
    {
        $id = Operations::newId();
        $operations = $this->app->make(Operations::class);
        $operations->insertClaimed($this->mirror(), [
            'id' => $id, 'action' => Operations::RETRY, 'mode' => Operations::NONE, 'job_uuid' => '0c5b0c5b-0c5b-4c5b-8c5b-'.substr($id, 0, 12),
            'occurrence_id' => 1, 'queue_connection' => 'database', 'queue' => 'default', 'queue_fingerprint' => $fingerprint,
            'deadline' => $deadline, 'next_check_at' => 1000.0, 'source' => 'cli',
        ]);

        if ($state === Operations::PUBLISHED) {
            $operations->markPublished($this->mirror(), $id, 1);
        }

        if ($awaiting) {
            $operations->await($this->mirror(), $id, Operations::SQLITE_HOST_FILE, Clock::now());
        }

        return $id;
    }
}
