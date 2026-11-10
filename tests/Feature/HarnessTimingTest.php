<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature;

use Justrau\Orrery\Tests\Support\HarnessTiming;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The opt-in harness profile (Support\HarnessTiming), in a PHP process of
 * its own, because the profile reads its variable once per process.
 */
#[Group('harness')]
final class HarnessTimingTest extends TestCase
{
    /**
     * Rejects: a profile that keeps its variable in the environment, so a
     * child process (a worker, a nested bin/test run) writes the same file
     * and the parent's table is not the only one; a profile that writes
     * nothing, or loses the phase counts.
     */
    public function test_the_profile_writes_its_phases_and_children_do_not_inherit_it(): void
    {
        $directory = sys_get_temp_dir().'/orrery-timing-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $file = $directory.'/timing.json';
        $script = $directory.'/probe.php';
        file_put_contents($script, '<?php
require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';
'.HarnessTiming::class.'::measure("one", static fn () => null);
'.HarnessTiming::class.'::measure("one", static fn () => null);
echo json_encode(["parent" => getenv("'.HarnessTiming::VARIABLE.'"), "server" => array_key_exists("'.HarnessTiming::VARIABLE.'", $_SERVER), "env" => array_key_exists("'.HarnessTiming::VARIABLE.'", $_ENV), "child" => trim((string) shell_exec(escapeshellarg(PHP_BINARY)." -r ".escapeshellarg("echo getenv(\''.HarnessTiming::VARIABLE.'\') === false ? \'unset\' : \'set\';")))]);
');

        try {
            $command = 'env '.HarnessTiming::VARIABLE.'='.escapeshellarg($file).' '.escapeshellarg(PHP_BINARY).' -d variables_order=EGPCS '.escapeshellarg($script);
            $output = json_decode((string) shell_exec($command), true);

            self::assertSame(['parent' => false, 'server' => false, 'env' => false, 'child' => 'unset'], $output, 'the variable stayed in the environment of the process or of its child');
            $table = json_decode((string) file_get_contents($file), true);
            self::assertSame(2, $table['phases']['one']['count'] ?? null, 'the profile did not write both measured calls');
        } finally {
            foreach ([$file, $script] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            rmdir($directory);
        }
    }
}
