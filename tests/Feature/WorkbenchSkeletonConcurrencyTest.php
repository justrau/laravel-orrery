<?php

namespace Justrau\Orrery\Tests\Feature;

use Justrau\Orrery\Tests\Support\WorkbenchCliTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Workbench\App\Support\WorkbenchSkeleton;

/**
 * Two launchers that repair the skeleton vendor link at the same time.
 *
 * Launcher A decides, inside WorkbenchSkeleton::ensureVendorLink(), to make a missing link or to
 * replace a stale one, and holds at that decision (barrier files, tests/Support/bin/
 * ensure-vendor-link.php). Launcher B then starts. The test waits until B either finishes or
 * reports that another process holds the link lock, and only then releases A. So the outcome does
 * not depend on timing:
 *  - with the lock, B waits for A, then reads the path again, keeps A's link and returns;
 *  - without the lock, B makes its own link and returns, and A then fails on B's link (missing
 *    case) or removes it (stale case).
 * The test requires that B waited for the lock, that both launchers succeeded, and that the link
 * each one returned with is still the link at the end.
 *
 * Everything is in the test-owned outside directory: a temporary skeleton and two temporary vendor
 * directories. The test is in the `workbench` group because it starts child processes.
 */
#[Group('workbench')]
final class WorkbenchSkeletonConcurrencyTest extends WorkbenchCliTestCase
{
    private const BARRIER_SECONDS = 30.0;

    /**
     * @return array<string, array{bool}>
     */
    public static function linkStates(): array
    {
        return [
            'missing link' => [false],
            'stale link' => [true],
        ];
    }

    #[DataProvider('linkStates')]
    public function test_a_second_launcher_waits_and_keeps_the_first_launchers_link(bool $stale): void
    {
        $skeleton = $this->outsideDirectory.DIRECTORY_SEPARATOR.'skeleton';
        $vendor = $this->outsideDirectory.DIRECTORY_SEPARATOR.'vendor';
        $otherVendor = $this->outsideDirectory.DIRECTORY_SEPARATOR.'other-vendor';
        $barrier = $this->outsideDirectory.DIRECTORY_SEPARATOR.'barrier';

        foreach ([$skeleton, $vendor, $otherVendor, $barrier] as $directory) {
            mkdir($directory, 0700);
        }

        file_put_contents($vendor.'/autoload.php', "<?php // the checkout's autoloader\n");
        file_put_contents($otherVendor.'/autoload.php', "<?php // another autoloader\n");
        $link = $skeleton.DIRECTORY_SEPARATOR.'vendor';

        if ($stale) {
            symlink($otherVendor, $link);
        }

        $script = $this->repository.'/tests/Support/bin/ensure-vendor-link.php';
        $a = $this->startPhp([$script, $skeleton, $vendor, $barrier, 'a', 'hold'], []);
        $this->waitUntil(static fn (): bool => is_file($barrier.'/a-decided.reached'), $a, 'Launcher A did not reach its decision');

        $b = $this->startPhp([$script, $skeleton, $vendor, $barrier, 'b', 'run'], []);
        $this->waitUntil(static fn (): bool => ! $b->isRunning() || is_file($barrier.'/b-lock-wait.reached'), null, 'Launcher B neither finished nor waited for the lock');
        $bFinishedFirst = ! $b->isRunning();

        touch($barrier.'/a-decided.release');
        $this->waitForExit($a);
        $this->waitForExit($b);

        $this->assertSame(0, $a->getExitCode(), "Launcher A failed:\n".$a->getOutput().$a->getErrorOutput());
        $this->assertSame(0, $b->getExitCode(), "Launcher B failed:\n".$b->getOutput().$b->getErrorOutput());

        clearstatcache(true, $link);
        $this->assertTrue(is_link($link));
        $this->assertTrue(WorkbenchSkeleton::vendorAccepted($link, $vendor));
        $final = (string) lstat($link)['ino'];
        $this->assertSame($final, (string) file_get_contents($barrier.'/b.done'), 'The link that launcher B returned with was replaced.');
        $this->assertSame($final, (string) file_get_contents($barrier.'/a.done'), 'The link that launcher A returned with was replaced.');
        $this->assertFalse($bFinishedFirst, 'Launcher B finished while launcher A held its decision: nothing serialized them.');
        $this->assertFileExists($barrier.'/b-lock-wait.reached', 'Launcher B never found the link lock taken.');
        $this->assertFileExists($otherVendor.'/autoload.php', 'A launcher changed the other vendor directory.');
    }

    /**
     * Wait until the condition holds. Fail when the child exits first or the time runs out.
     */
    private function waitUntil(callable $condition, ?Process $child, string $what): void
    {
        $deadline = microtime(true) + self::BARRIER_SECONDS;

        while (microtime(true) < $deadline) {
            clearstatcache();

            if ($condition()) {
                return;
            }

            if ($child !== null) {
                $child->checkTimeout();

                if (! $child->isRunning()) {
                    self::fail($what.": the child exited first.\n".$child->getOutput().$child->getErrorOutput());
                }
            }

            usleep(10_000);
        }

        self::fail(sprintf('%s within %d seconds.', $what, self::BARRIER_SECONDS));
    }

    private function waitForExit(Process $child): void
    {
        while ($child->isRunning()) {
            $child->checkTimeout();
            usleep(10_000);
        }
    }
}
