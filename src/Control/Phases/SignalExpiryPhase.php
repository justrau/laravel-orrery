<?php

namespace Justrau\Orrery\Control\Phases;

use Illuminate\Container\Container;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Database\Models\Signal;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Monitoring\NativePause;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The deletion of expired signals and stale queue annotations in
 * orrery:tick.
 *
 * - Expired signals: workers already ignore an expired signal at read time;
 *   this phase only removes the row. orrery_signals.expires_at keeps its
 *   dateTime type (the exception of the time rule), in UTC (UtcDateTime),
 *   so the string comparison follows the epoch also in the hour that
 *   repeats when daylight saving time ends. The cutoff is the
 *   database clock, the domain in which terminate() wrote
 *   expires_at, so a tick host with a wrong clock never deletes a live
 *   signal. The phase reads that clock once per run, and only when a row
 *   has an expiry.
 * - Stale annotations: a queue annotation (a pause row of scope `queue`)
 *   whose Laravel flag is off (a native queue:continue ended the pause) is
 *   deleted. A run checks at most ANNOTATIONS_PER_RUN annotations by id
 *   after the cursor CURSOR_KEY (orrery_settings), so annotations past the
 *   first 500 live ones are reached in later runs; a run that finds fewer
 *   starts the next run from the first id again. The native
 *   state comes from NativePause::read(), so none is deleted when the cache
 *   store is not safe (D17), when the read fails, or while Laravel's global
 *   pause masks the queues' own flags. The delete names the row's
 *   issued_at, so an annotation that a new pause refreshed after the read
 *   stays.
 */
final class SignalExpiryPhase implements TickPhase
{
    /** The most annotations that one run checks (one native read each at most). */
    public const ANNOTATIONS_PER_RUN = 500;

    /** The orrery_settings row with the last annotation id that a run checked. */
    public const CURSOR_KEY = 'signal_expiry_cursor';

    public function __construct(private readonly NativePause $native) {}

    public function name(): string
    {
        return 'signal_expiry';
    }

    public function priority(): int
    {
        return 300;
    }

    public function run(TickBudget $budget): void
    {
        if (Signal::query()->whereNotNull('expires_at')->exists()) {
            $now = DatabaseClock::now(Container::getInstance()->make(PackageSession::class)->mirror());

            Signal::query()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', SignalState::dateTime($now))
                ->delete();
        }

        $this->removeStaleAnnotations();
    }

    private function removeStaleAnnotations(): void
    {
        $settings = Container::getInstance()->make(PackageSession::class)->mirror()->table('orrery_settings');
        $cursor = (int) (clone $settings)->where('key', self::CURSOR_KEY)->value('value');
        $annotations = Signal::query()
            ->where('signal', SignalState::PAUSE)
            ->where('scope', 'queue')
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit(self::ANNOTATIONS_PER_RUN)
            ->get(['id', 'scope_value', 'issued_at']);
        // A short page reached the end: the next run starts from the first id again.
        $next = $annotations->count() < self::ANNOTATIONS_PER_RUN ? 0 : (int) $annotations->last()?->id;

        if ($next !== $cursor) {
            (clone $settings)->upsert([['key' => self::CURSOR_KEY, 'value' => (string) $next]], ['key'], ['value']);
        }

        if ($annotations->isEmpty()) {
            return;
        }

        $queues = [];

        foreach ($annotations as $annotation) {
            $queues[(int) $annotation->id] = self::split((string) $annotation->scope_value);
        }

        $states = $this->native->read(array_values($queues));

        foreach ($annotations as $annotation) {
            [$connection, $queue] = $queues[(int) $annotation->id];

            if (($states[NativePause::key($connection, $queue)]['queue'] ?? null) !== false) {
                continue;
            }

            // The stored decimal (a string on MySQL and Postgres; a REAL on
            // SQLite, written with six decimals), never PHP's 14-digit cast.
            $issuedAt = $annotation->getRawOriginal('issued_at');

            Signal::query()
                ->whereKey($annotation->id)
                ->where('scope', 'queue')
                ->where('issued_at', is_string($issuedAt) ? $issuedAt : Clock::toDecimal((float) $issuedAt))
                ->delete();
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function split(string $value): array
    {
        $parts = explode(':', $value, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
