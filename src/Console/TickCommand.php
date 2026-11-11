<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Justrau\Orrery\Database\Models\Setting;
use Justrau\Orrery\Maintenance\TickOutcome;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Maintenance\TickRunner;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\OffSwitch;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The periodic maintenance of the package.
 *
 * - When orrery_jobs or orrery_settings is missing on the mirror, or the
 *   check itself fails, it prints one warning, records the problem in the
 *   degradation record, and exits 0.
 * - Otherwise it writes the setting observer_tick_at (the tick liveness),
 *   runs the phases tagged `orrery.tick-phases` (TickRunner), stores the
 *   run's outcome (TickOutcome: failed phases, deadline skips in a row,
 *   the finish time), and flushes the degradation record.
 * - While the package is off (ORRERY_ENABLED=false) it prints "Skipped:"
 *   with the reason and does nothing (whileDisabled()).
 * - It exits 0 in every case: a mirror outage or the off switch never fails
 *   the scheduler.
 */
#[AsCommand(name: 'orrery:tick')]
class TickCommand extends Command
{
    use RefusesWhileDisabled;

    public const PHASES_TAG = 'orrery.tick-phases';

    /** @var string */
    protected $signature = 'orrery:tick';

    /** @var string */
    protected $description = 'Run the periodic Orrery maintenance phases.';

    /**
     * While the package is off: a skip, exit 0 (a self-scheduled tick must
     * not fail every minute).
     */
    protected function whileDisabled(): int
    {
        $this->components->info('Skipped: '.OffSwitch::MESSAGE);

        return self::SUCCESS;
    }

    public function handle(Container $container, PackageSession $sessions, Watcher $watcher, TickRunner $runner): int
    {
        $tables = $watcher->attempt('tick:tables', static function () use ($sessions): array {
            $schema = $sessions->mirror()->getSchemaBuilder();

            return array_values(array_filter(['orrery_jobs', 'orrery_settings'], static fn (string $table): bool => ! $schema->hasTable($table)));
        });

        if (! $tables->succeeded()) {
            $this->warn('orrery:tick cannot read the package tables on the mirror connection, so it did nothing. The health events record the error, or the log when the package database cannot be reached.');
            Degradation::flush();

            return self::SUCCESS;
        }

        /** @var list<string> $missing */
        $missing = $tables->value();

        if ($missing !== []) {
            Degradation::error('tick:missing_tables', 'Missing package tables: '.implode(', ', $missing));
            $this->warn('orrery:tick found no '.implode(' or ', $missing).' table on the mirror connection. Run "php artisan migrate".');
            Degradation::flush();

            return self::SUCCESS;
        }

        $startedAt = Clock::now();
        $watcher->run('tick:liveness', static function () use ($startedAt): void {
            Setting::query()->updateOrCreate(['key' => 'observer_tick_at'], ['value' => Clock::toDecimal($startedAt)]);
        });

        /** @var iterable<TickPhase> $phases */
        $phases = $container->tagged(self::PHASES_TAG);
        $report = $runner->run($phases);

        // The outcome for the monitoring ping: failed phases and deadline skips in a row (TickOutcome).
        $watcher->run('tick:outcome', static fn () => $container->make(TickOutcome::class)->record($startedAt, Clock::now(), $report));

        Degradation::flush();

        return self::SUCCESS;
    }
}
