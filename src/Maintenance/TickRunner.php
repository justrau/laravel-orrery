<?php

namespace Justrau\Orrery\Maintenance;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * Runs the tick phases.
 *
 * - The phases run in priority() order, lowest first (then by name), never in
 *   the container's tag order.
 * - Each phase runs inside the watcher as `tick:<name>`. A throwing phase is
 *   recorded, and the next phase still runs.
 * - The cooperative deadline is `orrery.tick.deadline_seconds` (default 30)
 *   after the start. A phase that ends after the deadline records
 *   `tick_overrun:<name>` in the degradation record: it is the stored
 *   last_error_operation of the window. The runner then skips every
 *   remaining phase; each one counts as dropped (`tick_skipped:<name>` in
 *   the in-memory errors map) and never replaces the stored overrun.
 * - A phase that implements RunsPastDeadline (the three notification
 *   phases: stuck_events, stuck_notifications and long_wait, which are
 *   last) is never skipped: it gets the rest of the budget, or
 *   its own share when that is later, so a slow tick never
 *   ends the alerts. It records `tick_overrun:<name>` when it ends after
 *   its own budget.
 * - Each phase runs on the package session (PackageSession::mirror()),
 *   whose statements carry the session's lock and statement timeouts.
 */
final class TickRunner
{
    private const DEFAULT_DEADLINE_SECONDS = 30.0;

    public function __construct(
        private readonly Watcher $watcher,
    ) {}

    /**
     * @param  iterable<TickPhase>  $phases
     */
    public function run(iterable $phases): TickReport
    {
        $seconds = $this->config()->get('orrery.tick.deadline_seconds', self::DEFAULT_DEADLINE_SECONDS);
        $budget = new TickBudget(Clock::monotonic() + (is_numeric($seconds) ? (float) $seconds : self::DEFAULT_DEADLINE_SECONDS));

        $ordered = [];

        foreach ($phases as $phase) {
            $ordered[] = $phase;
        }

        usort($ordered, static fn (TickPhase $a, TickPhase $b): int => [$a->priority(), $a->name()] <=> [$b->priority(), $b->name()]);

        /** @var list<string> $ran */
        $ran = [];
        /** @var list<string> $failed */
        $failed = [];
        /** @var list<string> $overruns */
        $overruns = [];
        /** @var list<string> $skipped */
        $skipped = [];
        $stopped = false;

        foreach ($ordered as $phase) {
            $name = $phase->name();
            $late = $phase instanceof RunsPastDeadline;

            if (! $late && ($stopped || $budget->exhausted())) {
                // Only the phase that exceeded the deadline is stored as the
                // overrun. When the deadline passed before any phase ran, the
                // first skipped phase carries it.
                if ($overruns === [] && $skipped === []) {
                    Degradation::error('tick_overrun:'.$name, "The deadline passed before the phase {$name} started.");
                }

                $stopped = true;
                $skipped[] = $name;
                Degradation::skipped('tick_skipped:'.$name);

                continue;
            }

            // A phase that runs past the deadline gets at least its own share.
            $own = $late ? $budget->atLeast($phase->shareSeconds()) : $budget;
            $ran[] = $name;
            try {
                $result = $this->watcher->attempt('tick:'.$name, static fn () => $phase->run($own));
            } finally {
                // Each phase is a unit of work (D18): no automatic reader of a
                // host SQLite file stays open into the next phase, and the next
                // phase confirms the journal mode again.
                PackageSession::releaseAutomatic();
            }

            if (! $result->succeeded()) {
                $failed[] = $name;
            }

            if ($own->exhausted()) {
                $stopped = true;
                $overruns[] = $name;
                Degradation::error('tick_overrun:'.$name, "The tick phase {$name} ended after the deadline.");
            }
        }

        return new TickReport($ran, $failed, $overruns, $skipped);
    }

    /**
     * The configuration of the running request, read when it is used: this
     * object is resolved at boot, and an Octane request has its
     * own configuration sandbox.
     */
    private function config(): Repository
    {
        return Container::getInstance()->make('config');
    }
}
