<?php

namespace Justrau\Orrery\Tests\Feature;

use Justrau\Orrery\Tests\Support\WorkbenchCliTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The CLI test helper bounds every child: a stalled child cannot hold the checkout-wide lock.
 *
 * The fixture child writes its PID and then sleeps longer than its timeout. The helper must stop
 * it at the timeout, reap it (a zombie still answers signal 0), and leave the lock free for
 * another process once the owned resources are released.
 */
#[Group('workbench')]
final class WorkbenchCliHelperTest extends WorkbenchCliTestCase
{
    private const CHILD_SLEEP_SECONDS = 5;

    private const SHORT_TIMEOUT_SECONDS = 1.0;

    public function test_a_child_that_outlives_its_timeout_is_stopped_and_the_lock_is_released(): void
    {
        $pidFile = $this->ownedDirectory.DIRECTORY_SEPARATOR.'child.pid';
        $started = microtime(true);
        $timedOut = false;

        try {
            $this->runPhp(['-r', self::sleepingChild()], ['WORKBENCH_TEST_PID_FILE' => $pidFile], null, self::SHORT_TIMEOUT_SECONDS);
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        }

        $elapsed = microtime(true) - $started;

        $this->assertTrue($timedOut, sprintf('The helper did not enforce the %.1f s timeout; the child ran to its end.', self::SHORT_TIMEOUT_SECONDS));
        $this->assertLessThan(self::CHILD_SLEEP_SECONDS - 1, $elapsed, 'The failure was not bounded by the timeout.');
        $this->assertChildGone($pidFile);
        $this->assertLockAcquirableAfterRelease();
    }

    public function test_a_child_is_stopped_when_the_wait_callback_throws(): void
    {
        $pidFile = $this->ownedDirectory.DIRECTORY_SEPARATOR.'child.pid';
        $thrown = null;

        try {
            $this->runPhp(['-r', self::sleepingChild()], ['WORKBENCH_TEST_PID_FILE' => $pidFile], static function () use ($pidFile): void {
                if (is_file($pidFile) && filesize($pidFile) > 0) {
                    throw new RuntimeException('The sentinel callback failed.');
                }
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame('The sentinel callback failed.', $thrown->getMessage());
        $this->assertChildGone($pidFile);
        $this->assertLockAcquirableAfterRelease();
    }

    private static function sleepingChild(): string
    {
        return sprintf('file_put_contents(getenv("WORKBENCH_TEST_PID_FILE"), (string) getmypid()); sleep(%d);', self::CHILD_SLEEP_SECONDS);
    }

    private function assertChildGone(string $pidFile): void
    {
        $pid = (int) file_get_contents($pidFile);

        $this->assertGreaterThan(0, $pid, 'The child did not record its PID.');
        // Signal 0 only checks existence. A child that is stopped but not reaped (a zombie) still
        // answers it, so false means stopped and reaped.
        $this->assertFalse(posix_kill($pid, 0), 'The child process '.$pid.' survived.');
    }

    /**
     * Release the owned resources as tearDown() does, then take the lock from another process.
     */
    private function assertLockAcquirableAfterRelease(): void
    {
        $this->releaseOwnedResources();

        $probe = new Process([PHP_BINARY, '-r', '$handle = fopen($argv[1], "c"); exit(flock($handle, LOCK_EX | LOCK_NB) ? 0 : 1);', self::lockPath()], null, null, null, 10);
        $probe->run();

        $this->assertSame(0, $probe->getExitCode(), 'Another process could not take the Workbench test lock.');
    }
}
