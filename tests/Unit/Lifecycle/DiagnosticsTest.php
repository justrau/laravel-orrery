<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Lifecycle;

use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Justrau\Orrery\Lifecycle\Diagnostics;
use Justrau\Orrery\Lifecycle\EnforcedRule;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The terminal explanation of a failed cycle.
 */
final class DiagnosticsTest extends TestCase
{
    private const FAILED_AT = 1_790_000_100.5;

    protected function setUp(): void
    {
        parent::setUp();

        // Loaded, as in a worker that threw it: TimeoutExceededException is then a known subclass of MaxAttemptsExceededException.
        self::assertTrue(class_exists(TimeoutExceededException::class));
    }

    /**
     * Rejects: a check of MaxAttemptsExceededException before
     * TimeoutExceededException (the timeout extends it, so a timeout would
     * read as an attempt limit), and a timeout without its effective value
     * and failOnTimeout.
     */
    public function test_a_timeout_is_checked_before_max_attempts(): void
    {
        $terminal = Diagnostics::terminal(TimeoutExceededException::class, 1, self::FAILED_AT, $this->rule(tries: 3, timeout: 0, failOnTimeout: true));

        self::assertSame('timeout', $terminal['kind']);
        self::assertSame(['timeout' => 0, 'timeout_source' => 'payload', 'fail_on_timeout' => true, 'attempt' => 1], $terminal['facts']);
    }

    /**
     * Rejects: "the attempt limit was reached" for a job whose retry
     * deadline passed before its failure.
     */
    public function test_max_attempts_after_the_deadline_is_the_passed_deadline(): void
    {
        $deadline = (int) self::FAILED_AT - 10;
        $terminal = Diagnostics::terminal(MaxAttemptsExceededException::class, 7, self::FAILED_AT, $this->rule(tries: 100, deadline: $deadline));

        self::assertSame('deadline_passed', $terminal['kind']);
        self::assertSame(['deadline' => $deadline, 'failed_at' => self::FAILED_AT, 'attempt' => 7], $terminal['facts']);
    }

    /**
     * Laravel's check before an attempt fails the job when its attempt is
     * more than the tries value.
     *
     * Rejects: a deadline explanation without a deadline, and an attempt
     * limit without its value and source.
     */
    public function test_max_attempts_without_a_deadline_is_the_attempt_limit(): void
    {
        $terminal = Diagnostics::terminal(MaxAttemptsExceededException::class, 4, self::FAILED_AT, $this->rule(tries: 3, triesSource: 'worker'));

        self::assertSame('attempt_limit', $terminal['kind']);
        self::assertSame(['tries' => 3, 'tries_source' => 'worker', 'max_exceptions' => null, 'attempt' => 4], $terminal['facts']);

        $unknown = Diagnostics::terminal(MaxAttemptsExceededException::class, 2, self::FAILED_AT, $this->rule(triesSource: 'unknown'));
        self::assertSame('attempt_limit', $unknown['kind'], 'unknown tries and no maxExceptions: only the attempt limit can have failed it');
        self::assertSame(['tries' => null, 'tries_source' => 'unknown', 'max_exceptions' => null, 'attempt' => 2], $unknown['facts']);
    }

    /**
     * A release with #[CountCrashesAsExceptions] (13.40 has it, 13.30 does
     * not) also throws MaxAttemptsExceededException when a job with that
     * attribute reaches its maxExceptions through counted
     * crashes (Worker::markJobAsFailedIfAlreadyExceedsMaxExceptions()).
     *
     * Rejects: "the attempt limit was reached" for a failure whose attempt is
     * within the tries value, or whose tries are unknown while maxExceptions
     * is set.
     */
    public function test_max_attempts_from_counted_crashes_is_not_the_attempt_limit(): void
    {
        $terminal = Diagnostics::terminal(MaxAttemptsExceededException::class, 3, self::FAILED_AT, $this->rule(tries: 10, maxExceptions: 2));

        self::assertSame('max_attempts_other', $terminal['kind']);
        self::assertSame(['deadline' => null, 'failed_at' => self::FAILED_AT, 'tries' => 10, 'tries_source' => 'payload', 'max_exceptions' => 2, 'attempt' => 3], $terminal['facts']);

        self::assertSame('max_attempts_other', Diagnostics::terminal(MaxAttemptsExceededException::class, 3, self::FAILED_AT, $this->rule(maxExceptions: 2))['kind'], 'unknown tries with maxExceptions');
        self::assertSame('max_attempts_other', Diagnostics::terminal(MaxAttemptsExceededException::class, 3, self::FAILED_AT, $this->rule(tries: 0))['kind'], 'unlimited tries');
        self::assertSame('max_attempts_other', Diagnostics::terminal(MaxAttemptsExceededException::class, 3, self::FAILED_AT, $this->rule(tries: 3))['kind'], 'the check before an attempt fails only an attempt above the tries');

        // A passed deadline with maxExceptions: a counted crash can also fail it within the deadline's last second.
        $deadline = (int) self::FAILED_AT - 10;
        self::assertSame('max_attempts_other', Diagnostics::terminal(MaxAttemptsExceededException::class, 3, self::FAILED_AT, $this->rule(tries: 10, deadline: $deadline, maxExceptions: 2))['kind']);
    }

    /**
     * Rejects: a passed deadline claimed for a MaxAttemptsExceededException
     * that failed at or before its deadline (Laravel does not produce it,
     * but different host clocks can): both values are shown, no cause.
     */
    public function test_max_attempts_before_the_deadline_shows_both_values(): void
    {
        $deadline = (int) self::FAILED_AT;
        $terminal = Diagnostics::terminal(MaxAttemptsExceededException::class, 2, self::FAILED_AT, $this->rule(tries: 5, deadline: $deadline));

        self::assertSame('max_attempts_other', $terminal['kind']);
        self::assertSame(['deadline' => $deadline, 'failed_at' => self::FAILED_AT, 'tries' => 5, 'tries_source' => 'payload', 'max_exceptions' => null, 'attempt' => 2], $terminal['facts']);
    }

    /**
     * A manual fail() or any other exception: the limits at the failure
     * time are conditions, never a cause.
     *
     * Rejects: an attempt limit or a passed deadline claimed as the cause of
     * an application exception, and conditions that leave out the deadline
     * state.
     */
    public function test_any_other_exception_gives_conditions(): void
    {
        $deadline = (int) self::FAILED_AT + 3600;
        $terminal = Diagnostics::terminal(RuntimeException::class, 2, self::FAILED_AT, $this->rule(tries: 2, deadline: $deadline, maxExceptions: 1));

        self::assertSame('other', $terminal['kind']);
        self::assertSame([
            'attempt' => 2,
            'tries' => 2,
            'tries_source' => 'payload',
            'deadline' => $deadline,
            'deadline_passed' => false,
            'max_exceptions' => 1,
            'fail_on_timeout' => false,
            'timeout' => null,
            'timeout_source' => 'unknown',
        ], $terminal['facts']);

        $passed = Diagnostics::terminal(null, 4, self::FAILED_AT, $this->rule(deadline: (int) self::FAILED_AT - 1));
        self::assertSame('other', $passed['kind']);
        self::assertTrue($passed['facts']['deadline_passed']);
        self::assertNull(Diagnostics::terminal(null, 4, null, $this->rule(deadline: 5))['facts']['deadline_passed'], 'An unknown failure time leaves the deadline state unknown.');
        self::assertNull(Diagnostics::terminal(null, 4, self::FAILED_AT, $this->rule())['facts']['deadline_passed']);
    }

    /**
     * Rejects: a class name read by autoloading the stored string.
     */
    public function test_an_unknown_class_is_not_loaded(): void
    {
        $loaded = [];
        $loader = static function (string $class) use (&$loaded): void {
            $loaded[] = $class;
        };
        spl_autoload_register($loader);

        try {
            $terminal = Diagnostics::terminal('App\\Exceptions\\NeverLoaded'.bin2hex(random_bytes(4)), 1, self::FAILED_AT, $this->rule());
        } finally {
            spl_autoload_unregister($loader);
        }

        self::assertSame('other', $terminal['kind']);
        self::assertSame([], $loaded);
    }

    /**
     * @return array{kind: string, deadline: int|null, tries: int|null, tries_source: string, other_stops: array{max_exceptions: int|null, fail_on_timeout: bool, timeout: int|null, timeout_source: string}}
     */
    private function rule(?int $tries = null, string $triesSource = 'payload', ?int $deadline = null, ?int $maxExceptions = null, bool $failOnTimeout = false, ?int $timeout = null): array
    {
        return EnforcedRule::of($tries, $triesSource, $deadline, self::FAILED_AT, $maxExceptions, $failOnTimeout, $timeout, 'payload');
    }
}
