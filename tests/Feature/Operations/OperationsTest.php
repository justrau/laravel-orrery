<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use InvalidArgumentException;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;

/**
 * The operation ledger's transitions.
 * Kind: new behaviour.
 */
final class OperationsTest extends TestCase
{
    /**
     * Rejects: a published transition without `WHERE state = 'claimed'` (a stale publisher would revive
     * an aborted or done operation).
     */
    public function test_published_needs_claimed(): void
    {
        $id = $this->claim();

        self::assertTrue($this->ledger()->markPublished($this->mirror(), $id, 7));
        self::assertFalse($this->ledger()->markPublished($this->mirror(), $id, 8), 'One winner.');
        self::assertSame(7, (int) $this->row($id)->result_job_id);

        self::assertTrue($this->ledger()->markDone($this->mirror(), $id));
        self::assertFalse($this->ledger()->markPublished($this->mirror(), $id, 9), 'done is terminal.');
        self::assertSame(Operations::DONE, $this->row($id)->state);
    }

    /**
     * Rejects: an abort that runs after the publication (claimed → aborting from published), and
     * terminal states that a later transition changes.
     */
    public function test_aborting_needs_claimed_and_terminal_states_stay(): void
    {
        $published = $this->claim();
        $this->ledger()->markPublished($this->mirror(), $published, 7);
        self::assertFalse($this->ledger()->markAborting($this->mirror(), $published, 'cancelled'));

        $aborted = $this->claim();
        self::assertTrue($this->ledger()->markAborting($this->mirror(), $aborted, 'cancelled', 'the resolver cancelled'));
        self::assertFalse($this->ledger()->markAborting($this->mirror(), $aborted, 'deadline_passed'), 'A loser reads aborting and continues.');
        self::assertFalse($this->ledger()->markDone($this->mirror(), $aborted), 'An aborting operation is never done.');
        self::assertTrue($this->ledger()->markAborted($this->mirror(), $aborted));
        self::assertFalse($this->ledger()->markAborted($this->mirror(), $aborted));
        self::assertFalse($this->ledger()->reschedule($this->mirror(), $aborted, Clock::now() + 60, true));
        self::assertFalse($this->ledger()->await($this->mirror(), $aborted, Operations::SQLITE_HOST_FILE, Clock::now()));

        $row = $this->row($aborted);
        self::assertSame(Operations::ABORTED, $row->state);
        self::assertSame('cancelled', $row->error_code, 'The abort keeps its first code.');
    }

    /**
     * Rejects: an awaiting time that each re-check moves (the age of the wait is lost), an abort code
     * that the awaiting mark overwrites, and awaiting fields that stay at a terminal state.
     */
    public function test_awaiting_keeps_its_first_time_and_the_error_code(): void
    {
        $id = $this->claim();
        $this->ledger()->markAborting($this->mirror(), $id, 'record_delete_failed');

        self::assertTrue($this->ledger()->await($this->mirror(), $id, Operations::SQLITE_READS_NOT_ALLOWED, 1_790_000_000.0));
        self::assertTrue($this->ledger()->await($this->mirror(), $id, Operations::SQLITE_HOST_FILE, 1_790_000_500.0));

        $row = $this->row($id);
        self::assertEqualsWithDelta(1_790_000_000.0, (float) $row->awaiting_since, 0.001);
        self::assertSame(Operations::SQLITE_HOST_FILE, $row->awaiting_reason);
        self::assertSame('record_delete_failed', $row->error_code);

        self::assertTrue($this->ledger()->markAborted($this->mirror(), $id));
        $row = $this->row($id);
        self::assertNull($row->awaiting_since);
        self::assertNull($row->awaiting_reason);

        $this->expectException(InvalidArgumentException::class);
        $this->ledger()->await($this->mirror(), $this->claim(), 'later', Clock::now());
    }

    /**
     * Rejects: a reschedule that counts a check that it was told not to count (the deadline-ahead case of the
     * runner), and one that loses the count.
     */
    public function test_reschedule_counts_only_undecided_checks(): void
    {
        $id = $this->claim();

        $this->ledger()->reschedule($this->mirror(), $id, 1_790_000_060.0, false);
        self::assertSame(0, (int) $this->row($id)->checks);

        $this->ledger()->reschedule($this->mirror(), $id, 1_790_000_120.0, true, 'queue_unreachable', 'connection refused');
        $this->ledger()->reschedule($this->mirror(), $id, 1_790_000_420.0, true);
        $row = $this->row($id);
        self::assertSame(2, (int) $row->checks);
        self::assertEqualsWithDelta(1_790_000_420.0, (float) $row->next_check_at, 0.001);
        self::assertSame('queue_unreachable', $row->error_code);
    }

    /**
     * Rejects: an error text longer than 2,000 bytes, and one that keeps a binding of the cause.
     */
    public function test_error_text_is_safe_and_bounded(): void
    {
        $text = Operations::errorText('retry:publish', new RuntimeException(str_repeat('ą', 3000)));

        self::assertLessThanOrEqual(Operations::MAX_ERROR_BYTES, strlen($text));
        self::assertTrue(mb_check_encoding($text, 'UTF-8'));
        self::assertStringContainsString('retry:publish', $text);
    }

    /**
     * Rejects: a claim without its occurrence or fingerprint, and an id that is not 32 hex characters.
     */
    public function test_claim_needs_its_columns(): void
    {
        try {
            $this->ledger()->insertClaimed($this->mirror(), ['id' => Operations::newId(), 'action' => Operations::RETRY]);
            self::fail('A claim without its columns is refused.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $this->ledger()->insertClaimed($this->mirror(), array_merge($this->columns(), ['id' => 'not-hex']));
    }

    private function claim(): string
    {
        $columns = $this->columns();
        $this->ledger()->insertClaimed($this->mirror(), $columns);

        return $columns['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(): array
    {
        return [
            'id' => Operations::newId(),
            'action' => Operations::RETRY,
            'mode' => Operations::IN_TX,
            'job_uuid' => '0f5a3c1e-6b1d-4e57-9a3e-3f1b2c4d5e6f',
            'occurrence_id' => 1,
            'queue_connection' => 'database',
            'queue' => 'default',
            'queue_fingerprint' => str_repeat('a', 64),
            'deadline' => 1_790_000_060.0,
            'next_check_at' => 1_790_000_060.0,
            'source' => 'dashboard',
        ];
    }

    private function row(string $id): object
    {
        $row = $this->ledger()->find($this->mirror(), $id);
        self::assertNotNull($row);

        return $row;
    }

    private function ledger(): Operations
    {
        return $this->app->make(Operations::class);
    }
}
