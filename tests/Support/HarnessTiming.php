<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

/**
 * An opt-in profile of the per-test harness work. With
 * ORRERY_TEST_HARNESS_TIMING=<file>, the test case and the reuse schema add
 * the wall time of each phase (the identity proof, the reuse preparation,
 * the reset after the test, the fresh migrations and their rollback) to a
 * table, and the process writes the table to <file> as JSON when it ends.
 * Without the variable, measure() only calls the work.
 *
 * The variable is read once per process, at the first measured phase, and
 * then removed from the environment of the process, so no child that the
 * process starts afterwards (a worker, a nested bin/test run) inherits it
 * and writes the same file. WorkerProcess also unsets every inherited
 * ORRERY_TEST_* variable for its children.
 */
final class HarnessTiming
{
    public const VARIABLE = 'ORRERY_TEST_HARNESS_TIMING';

    /** @var array<string, array{count: int, seconds: float}> */
    private static array $phases = [];

    private static ?string $file = null;

    private static bool $read = false;

    /**
     * Runs $work and, when the profile is on, adds its wall time to $phase.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function measure(string $phase, callable $work): mixed
    {
        if (! self::enabled()) {
            return $work();
        }

        $start = hrtime(true);

        try {
            return $work();
        } finally {
            $entry = self::$phases[$phase] ?? ['count' => 0, 'seconds' => 0.0];
            $entry['count']++;
            $entry['seconds'] += (hrtime(true) - $start) / 1e9;
            self::$phases[$phase] = $entry;
        }
    }

    public static function enabled(): bool
    {
        if (! self::$read) {
            self::$read = true;
            $value = getenv(self::VARIABLE);

            putenv(self::VARIABLE);
            unset($_ENV[self::VARIABLE], $_SERVER[self::VARIABLE]);

            if (is_string($value) && $value !== '') {
                self::$file = $value;
                register_shutdown_function(static function (): void {
                    ksort(self::$phases);
                    file_put_contents((string) self::$file, json_encode(['pid' => getmypid(), 'phases' => self::$phases], JSON_PRETTY_PRINT)."\n");
                });
            }
        }

        return self::$file !== null;
    }
}
