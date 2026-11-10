<?php

namespace Justrau\Orrery\Monitoring;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Events\StuckJobDetected as StuckJobEvent;
use Justrau\Orrery\Listeners\NotificationState;
use Justrau\Orrery\Notifications\StuckJobDetected;
use Justrau\Orrery\Notifications\StuckJobsSummary;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageTransaction;
use stdClass;
use Throwable;

/**
 * The notifications of the stuck incidents:
 * the tick phase `stuck_notifications` (priority
 * 950, after every phase but `long_wait`, so a slow channel delays none
 * of them)
 * sends each incident to the routes of the long-wait notification (mail,
 * Slack, SMS), apart from the event StuckJobDetected. A failed channel is
 * sent again without a second event. Every send runs outside any
 * transaction.
 *
 * **The incident's state** (schema step 10) shares the incident's
 * retention: `notified` (each channel: `sent`, `retry:<tries>`, `failed`,
 * `suppressed` or `skipped`), `notify_attempts` (the rounds claimed) and
 * `notify_next_at` (the next round; null: nothing more). The creation sets
 * `notify_next_at` to its time when `orrery.stuck.notify` is true (the
 * default). Turned off, each run ends the due rounds of at most END_ROWS
 * incidents (`notify_next_at` null), so turning it on again sends nothing
 * old.
 *
 * **The cooldowns** are rows of `orrery_notify_cooldowns` (step 10), one
 * per (connection, queue, signal, channel), keyed by a digest of the
 * byte-exact values: `cooldown_until`, `held` (the incidents held back
 * since the last message) and `summary_tries`. The settings row KEYS holds
 * only the channel backoffs (`down`), one entry per channel.
 *
 * **A round** (at most ROWS rounds per run, `notify_next_at <= now`,
 * `notify_attempts < MAX_ROUNDS`, ordered by `notify_next_at`, then the
 * key, the `notify_due` index; only a round that tries a send takes one of
 * them: an incident that only waits for a down channel, or whose channels
 * are all suppressed, takes none, and the run reads again for
 * the rows that such rounds held, while its share lasts); each round runs inside a
 * watched operation (`notification:round`), so a failed round is
 * recorded and the next rounds, the summaries and the prune still run:
 * 1. when every channel that the incident still needs is down, the round
 *    is not spent: `notify_next_at` moves to the end of the latest backoff
 *    (not when the application binds its own notification, whose via() can
 *    name a channel that no package route has);
 * 2. a guarded claim: `notify_attempts = k + 1`, `notify_next_at = now +
 *    backoff(k + 1)` (60 s after the first round, then 300 s; none after
 *    round MAX_ROUNDS, 10), `WHERE` the key `AND notify_attempts = k`. One
 *    tick wins a round;
 * 3. a job row that is gone, whose cycle ended or that left the state of
 *    its signal, or an incident older than MAX_AGE_SECONDS, ends the
 *    notifications;
 * 4. the notification (the application's binding) and its via() are built
 *    inside a watched operation (`notification:build`); a failure leaves
 *    each needed channel as it was (no try spent);
 * 5. a channel that via() no longer names (its route was removed) is
 *    `skipped`; a channel after the run's share, or a down one, keeps its
 *    state and tries for a later round; a channel that via() names but the
 *    package has no route for (the application's own notification) has no
 *    summary path, so it skips the cooldown and is sent for every incident
 * 6. the decision, in one package transaction: the cooldown rows of the
 *    round's keys are created when missing (insertOrIgnore, before it) and
 *    locked FOR UPDATE in key order; a key whose cooldown runs is counted
 *    (`held + 1`) and its channel `suppressed`; a free key is reserved
 *    (`cooldown_until = now + COOLDOWN_SECONDS`, its `held` taken as the
 *    message's "and N more"). So two ticks never both send for one key;
 * 7. the sends, each inside its own watched operation
 *    `notification:<channel>` (the driver check too; the check before the
 *    decision never throws: a driver that cannot be built fails in its
 *    send). A failure is recorded by the watcher and never thrown;
 * 8. the record, in one package transaction: a failed send gives its key's
 *    reservation and count back (guarded by the reserved time), marks the
 *    channel down in KEYS (DOWN_BACKOFF: 60 s, then 300 s; the row is
 *    created with EMPTY before and never deleted, so its lock holds a row)
 *    and leaves the channel `retry:<tries>`, `failed` after TRIES (3) real
 *    tries; a sent one ends the channel's backoff. The incident's state is
 *    written with `WHERE notify_attempts = k + 1`: when a later tick
 *    claimed the next round meanwhile (a round longer than its backoff),
 *    the write misses, and the miss is recorded (`notification:state_lost`).
 *
 * The locks are taken in one order (the cooldown rows by key, then KEYS),
 * so two ticks cannot deadlock. A tick that stops after a send and before
 * its record leaves the try unrecorded: that channel can be sent again in
 * each later round, at most MAX_ROUNDS (10) times for the incident.
 *
 * **Summaries.** A cooldown row whose cooldown ended with held-back
 * incidents (the `expiry` index) gets one StuckJobsSummary: its claim is a
 * guarded update on the locked row (one tick wins), the send runs outside,
 * a failure gives the count back (at most SUMMARY_TRIES tries, then the
 * count is dropped). **The prune** deletes at most PRUNE_ROWS rows whose
 * cooldown ended with nothing held, each run.
 *
 * The legacy Slack webhook message carries a send timeout of
 * SEND_TIMEOUT_SECONDS (StuckText). The Web API channel of
 * laravel/slack-notification-channel takes no timeout option: its HTTP
 * client's default applies (30 s), and the down backoff bounds how often a
 * slow channel is tried. The delivery stops when the mirror breaker is
 * open. No cache entry is read for a send; the warning marker of a missing
 * driver is the only one, read inside the watched send. Built for each
 * tick run; no state.
 */
final class StuckNotifications
{
    public const SENT = 'sent';

    public const RETRY = 'retry';

    public const FAILED = 'failed';

    public const SUPPRESSED = 'suppressed';

    public const SKIPPED = 'skipped';

    /** A send outcome: the share ended before the send (never stored). */
    private const UNSENT = 'unsent';

    /** The channel states that end a channel's notification of an incident. */
    public const DONE = [self::SENT, self::FAILED, self::SUPPRESSED, self::SKIPPED];

    /** The most real tries per channel and incident. */
    public const TRIES = 3;

    /**
     * The most rounds per incident: a safety bound for a channel that the
     * share or its backoff keeps from its tries; it is `failed` after it.
     * Also the most sends of one channel when every tick stops before its
     * record.
     */
    public const MAX_ROUNDS = 10;

    /** The seconds after the 1st round, and after each later one, before the next one. */
    public const BACKOFF = [1 => 60, 2 => 300];

    /** How long a channel stays down after its 1st and its later consecutive failures. */
    public const DOWN_BACKOFF = [1 => 60, 2 => 300];

    /** The most incidents per run. */
    public const ROWS = 50;

    /** The share of the phase, in seconds (within the tick budget). */
    public const SHARE_SECONDS = 10.0;

    /**
     * Whether the application binds its own StuckJobNotification: its via()
     * is known only once it is built.
     */
    private static function applicationNotification(): bool
    {
        return Container::getInstance()->bound(StuckJobDetected::class);
    }

    /**
     * The oldest incident that a round still sends: after a
     * channel was down for longer, a "looks stuck" message would describe an
     * old state. The event StuckJobDetected is not bound by it.
     */
    public const MAX_AGE_SECONDS = 21600;

    /** The cooldown of one (connection, queue, signal, channel) after a sent message. */
    public const COOLDOWN_SECONDS = 300;

    /** The most tries of one summary. */
    public const SUMMARY_TRIES = 3;

    /** The most summaries per run. */
    public const SUMMARY_ROWS = 50;

    /** The most cooldown rows that one run prunes. */
    public const PRUNE_ROWS = 500;

    /** The send timeout of the legacy Slack webhook, in seconds. */
    public const SEND_TIMEOUT_SECONDS = 5;

    /** The settings key of the channel backoffs. */
    public const KEYS = 'incidents_notify';

    /** The empty value of KEYS: the row exists once a round recorded, so its lock always holds a row. */
    public const EMPTY = '{"down":{}}';

    /** The table of the cooldowns. */
    public const COOLDOWNS = PackageSchema::NOTIFY_COOLDOWNS;

    /** The default of `orrery.stuck.notify`. */
    public const NOTIFY = true;

    /** How long a warning about a missing channel driver is not repeated. */
    public const WARNING_SECONDS = 86400;

    /** The hold point after the due read, before a round (tests). */
    public const BEFORE_CLAIM = 'incidents.notify_before_claim';

    /** The hold point before a round's decision transaction, after the claim (tests). */
    public const BEFORE_LOCK = 'incidents.notify_before_lock';

    /** The hold point after the decision's locked read of the cooldown rows (tests). */
    public const AFTER_LOCK = 'incidents.notify_after_lock';

    /** The hold point after a round's sends, before its record (tests). */
    public const BEFORE_RECORD = 'incidents.notify_before_record';

    /** The hold point after the summary candidates' read, before a claim (tests). */
    public const BEFORE_SUMMARY = 'incidents.notify_before_summary';

    /** The most due rounds that one run ends while the notifications are off. */
    public const END_ROWS = 1000;

    /** The watched operation of a round. */
    public const ROUND_OPERATION = 'notification:round';

    /** The watched operations of the summaries, the prune and a round's give-back. */
    public const SUMMARIES_OPERATION = 'notification:summaries';

    public const PRUNE_OPERATION = 'notification:prune';

    public const GIVE_BACK_OPERATION = 'notification:give_back';

    /** The width of the `channel` column of the cooldowns, in characters. */
    public const CHANNEL_CHARACTERS = 64;

    /** The watched operation of the notification's build and its via(). */
    public const BUILD_OPERATION = 'notification:build';

    /** The degradation operation of a state write that a later round's claim made miss. */
    public const STATE_LOST = 'notification:state_lost';

    private const TABLE = StuckIncidents::TABLE;

    private const SETTINGS = 'orrery_settings';

    private const JSON = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(
        private readonly Repository $config,
        private readonly Watcher $watcher,
        private readonly PackageTransaction $transactions,
    ) {}

    /**
     * `orrery.stuck.notify`: true unless set to false (a published
     * configuration without the key keeps the default).
     */
    public function enabled(): bool
    {
        $value = $this->config->get('orrery.stuck.notify', self::NOTIFY);

        return is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? self::NOTIFY);
    }

    /**
     * The digest of one cooldown key: sha256 of the byte-exact values (an
     * invalid UTF-8 value by its hex), as SampleCounts::series().
     */
    public static function keyId(string $connection, string $queue, string $signal, string $channel): string
    {
        $identity = ['notify'];

        foreach ([$connection, $queue, $signal, $channel] as $part) {
            $identity[] = mb_check_encoding($part, 'UTF-8') ? $part : ['hex' => bin2hex($part)];
        }

        return hash('sha256', (string) json_encode($identity, self::JSON));
    }

    /**
     * One run: the due rounds, the summaries and the prune, while $over is
     * false.
     *
     * @param  Closure(): bool  $over
     * @return array{sent: int, failed: int, retried: int, suppressed: int, summaries: int, ended: int, pruned: int, lost: int}
     */
    public function run(Connection $mirror, Closure $over): array
    {
        $report = ['sent' => 0, 'failed' => 0, 'retried' => 0, 'suppressed' => 0, 'summaries' => 0, 'ended' => 0, 'pruned' => 0, 'lost' => 0];

        if (! $this->enabled()) {
            $report['ended'] = $this->endDue($mirror);

            return $report;
        }

        $routes = self::routes();
        // The rounds of this run: only a round that tried a send takes one. An incident
        // that only waits for a down channel, or whose channels were all suppressed, takes none.
        $spent = 0;

        while ($spent < self::ROWS) {
            $limit = self::ROWS - $spent;
            $due = $this->due($mirror, $limit);
            $waited = 0;

            foreach ($due as $incident) {
                if ($over() || $this->watcher->breakerOpen()) {
                    break 2;
                }

                // A failed round is recorded; the later rounds, the summaries and the prune still run.
                $round = $this->watcher->attempt(self::ROUND_OPERATION, function () use ($mirror, $incident, $routes, $over, &$report): bool {
                    return $this->round($mirror, $incident, $routes, $over, $report);
                });

                if ($round->succeeded() && $round->value() === true) {
                    $waited++;
                } else {
                    $spent++;
                }
            }

            // The rounds that took no row left the due set: read again for the rows they held. Without
            // one, the rows are spent; after a short read nothing more is due.
            if ($waited === 0 || count($due) < $limit) {
                break;
            }
        }

        $this->watcher->attempt(self::SUMMARIES_OPERATION, function () use ($mirror, $routes, $over, &$report): void {
            $this->summaries($mirror, $routes, $over, $report);
        });
        $this->watcher->attempt(self::PRUNE_OPERATION, function () use ($mirror, &$report): void {
            $report['pruned'] = $this->prune($mirror);
        });

        return $report;
    }

    /**
     * At most $limit due incidents, in the order of `notify_due`.
     *
     * @return list<stdClass>
     */
    private function due(Connection $mirror, int $limit): array
    {
        /** @var list<stdClass> $rows */
        $rows = $mirror->table(self::TABLE)
            ->whereNotNull('notify_next_at')
            ->where('notify_next_at', '<=', Clock::toDecimal(Clock::now()))
            ->where('notify_attempts', '<', self::MAX_ROUNDS)
            // The order of notify_due: a stable order whatever the ties.
            ->orderBy('notify_next_at')
            ->orderBy('job_uuid')
            ->orderBy('cycle')
            ->orderBy('signal')
            ->limit($limit)
            ->get(['job_uuid', 'cycle', 'signal', 'first_seen_at', 'evidence', 'notified', 'notify_attempts'])
            ->all();

        return $rows;
    }

    /**
     * Ends the due rounds of at most END_ROWS incidents (the notifications
     * are off).
     */
    private function endDue(Connection $mirror): int
    {
        $rows = $mirror->table(self::TABLE)
            ->whereNotNull('notify_next_at')
            ->orderBy('notify_next_at')
            ->orderBy('job_uuid')
            ->orderBy('cycle')
            ->orderBy('signal')
            ->limit(self::END_ROWS)
            ->get(['job_uuid', 'cycle', 'signal'])
            ->all();
        $ended = 0;

        foreach (array_chunk($rows, 100) as $chunk) {
            $ended += $mirror->table(self::TABLE)
                ->whereNotNull('notify_next_at')
                ->where(static function ($query) use ($chunk): void {
                    foreach ($chunk as $row) {
                        $query->orWhere(static fn ($query) => $query->where('job_uuid', $row->job_uuid)->where('cycle', $row->cycle)->where('signal', $row->signal));
                    }
                })
                ->update(['notify_next_at' => null]);
        }

        return $ended;
    }

    /**
     * One incident's round (the class doc, steps 1 to 8). True when it tried
     * no send: it only waits for a down channel (step 1), or each of its
     * channels was suppressed, skipped or not reached. The run does not
     * count it against ROWS.
     *
     * @param  array<string, mixed>  $routes
     * @param  Closure(): bool  $over
     * @param  array{sent: int, failed: int, retried: int, suppressed: int, summaries: int, ended: int, pruned: int, lost: int}  $report
     */
    private function round(Connection $mirror, stdClass $incident, array $routes, Closure $over, array &$report): bool
    {
        Hooks::reach(self::BEFORE_CLAIM);
        $uuid = (string) $incident->job_uuid;
        $cycle = (int) $incident->cycle;
        $signal = (string) $incident->signal;
        $made = (int) $incident->notify_attempts;
        $round = $made + 1;
        $now = Clock::now();
        // No round after the last one.
        $backoff = $round < self::MAX_ROUNDS ? self::BACKOFF[min($round, 2)] : null;
        $key = static fn () => $mirror->table(self::TABLE)->where('job_uuid', $uuid)->where('cycle', $cycle)->where('signal', $signal);
        $state = self::state($incident->notified);
        $down = $this->down($mirror);
        $needed = array_values(array_filter(array_keys($routes), static fn (string $channel): bool => ! in_array($state[$channel] ?? null, self::DONE, true)));
        $downUntil = self::downUntil($down, $needed, $now);

        if ($made >= self::MAX_ROUNDS) {
            return false;
        }

        if ($needed !== [] && $downUntil !== null && ! self::applicationNotification()
            && count(array_filter($needed, static fn (string $channel): bool => self::isDown($down, $channel, $now))) === count($needed)) {
            // Every channel that it needs is down: the round waits, unspent. Not with the
            // application's own notification: its via() can name a channel without a package
            // route, which a down package channel must not hold back.
            $key()->where('notify_attempts', $made)->update(['notify_next_at' => Clock::toDecimal($downUntil)]);

            return true;
        }

        $claimed = $key()
            ->where('notify_attempts', $made)
            ->where('notify_attempts', '<', self::MAX_ROUNDS)
            ->whereNotNull('notify_next_at')
            ->update(['notify_attempts' => $round, 'notify_next_at' => $backoff === null ? null : Clock::toDecimal($now + $backoff)]);

        if ($claimed !== 1) {
            return false;
        }

        $job = $mirror->table('orrery_jobs')->where('uuid', $uuid)->first(['cycle', 'status', 'connection', 'queue', 'name']);

        if (! $job instanceof stdClass
            || (int) $job->cycle !== $cycle
            || ! StuckIncidents::stillHolds($signal, (string) $job->status)
            || $now - Clock::stored($incident->first_seen_at) > self::MAX_AGE_SECONDS) {
            // The job is gone, its cycle ended, it left the state of its signal (it finished in the
            // same cycle), or the incident is older than MAX_AGE_SECONDS (a channel was down for
            // long): nothing more to send for this incident.
            $key()->where('notify_attempts', $round)->update(['notify_next_at' => null]);

            return false;
        }

        $event = new StuckJobEvent($uuid, $cycle, $signal, (string) $job->connection, (string) $job->queue, (string) $job->name, self::decode($incident->evidence));
        $built = $this->watcher->attempt(self::BUILD_OPERATION, static function () use ($event, $routes): array {
            $notification = $event->toNotification();
            $channels = method_exists($notification, 'via') ? $notification->via(self::notifiable($routes)) : [];

            return is_array($channels) ? $channels : [];
        });
        // The channels to decide under the cooldown lock: channel => key.
        $wanted = [];
        // The channels that only the application's notification names: no route of the package,
        // so no summary path. The cooldown never holds them back.
        $direct = [];

        if (! $built->succeeded()) {
            foreach ($needed as $channel) {
                // No real try of the channel: it waits for a later round with its tries.
                $state[$channel] = self::retry(self::tries($state[$channel] ?? null));
            }
        } else {
            /** @var array<int, mixed> $channels */
            $channels = $built->value();
            $named = array_values(array_filter($channels, static fn (mixed $channel): bool => is_string($channel) && $channel !== ''));

            foreach ($state as $channel => $outcome) {
                if (self::isRetry($outcome) && ! in_array($channel, $named, true)) {
                    // Its route was removed: nothing will send it.
                    $state[$channel] = self::SKIPPED;
                }
            }

            foreach ($named as $channel) {
                /** @var string $channel */
                if (in_array($state[$channel] ?? null, self::DONE, true)) {
                    continue;
                }

                $state[$channel] = self::retry(self::tries($state[$channel] ?? null));

                if ($over() || self::isDown($down, $channel, Clock::now())) {
                    // Not reached in this run's share, or the channel is down: a later round tries it,
                    // and this round spends none of its tries.
                    continue;
                }

                if (! self::channelAvailable($channel)) {
                    // No driver: the channel reserves no cooldown.
                    $this->warnMissing($channel);
                    $state[$channel] = self::SKIPPED;

                    continue;
                }

                if (! array_key_exists($channel, $routes)) {
                    $direct[] = $channel;

                    continue;
                }

                $wanted[$channel] = self::keyId($event->connection, $event->queue, $signal, $channel);
            }
        }

        // 6. The decision under the cooldown rows' locks.
        $decided = $wanted === [] ? [] : $this->decide($mirror, $event, $wanted);
        $results = [];

        foreach ($wanted as $channel => $id) {
            $decision = $decided[$channel] ?? null;

            if ($decision === null) {
                continue;
            }

            if ($decision['send'] === false) {
                $state[$channel] = self::SUPPRESSED;
                $report['suppressed']++;

                continue;
            }

            if ($over()) {
                // The share ended before this send: the reservation goes back, the channel keeps its tries.
                $results[$channel] = self::UNSENT;

                continue;
            }

            // 7. The send, outside any transaction.
            $more = $decision['more'];
            $results[$channel] = $this->send(static fn (): Notification => $event->toNotification($more), $channel, $routes[$channel] ?? null);
        }

        foreach ($direct as $channel) {
            // No cooldown, so nothing to give back: a failed or unsent one waits for a later round.
            $results[$channel] = $over() ? self::UNSENT : $this->send(static fn (): Notification => $event->toNotification(), $channel, null);
        }

        // 8. The give-back of each failed or unsent reservation, a watched step of its own, so a
        //    record that fails afterwards loses neither the reservation nor the held-back count.
        $returned = array_keys(array_filter($results, static fn (string $outcome, int|string $channel): bool => isset($wanted[$channel]) && ($outcome === self::FAILED || $outcome === self::UNSENT), ARRAY_FILTER_USE_BOTH));

        if ($returned !== []) {
            $this->watcher->attempt(self::GIVE_BACK_OPERATION, function () use ($mirror, $returned, $wanted, $decided): void {
                $this->giveBack($mirror, $returned, $wanted, $decided);
            });
        }

        // 9. The record.
        Hooks::reach(self::BEFORE_RECORD);

        foreach ($results as $channel => $outcome) {
            if ($outcome === self::SENT) {
                $state[$channel] = self::SENT;
                $report['sent']++;
            } elseif ($outcome === self::SKIPPED) {
                $state[$channel] = self::SKIPPED;
            } elseif ($outcome !== self::UNSENT) {
                $tries = self::tries($state[$channel] ?? null) + 1;
                $state[$channel] = $tries >= self::TRIES || $backoff === null ? self::FAILED : self::retry($tries);
                $report[$state[$channel] === self::FAILED ? 'failed' : 'retried']++;
            }
        }

        if ($round >= self::MAX_ROUNDS) {
            foreach ($state as $channel => $outcome) {
                if (self::isRetry($outcome)) {
                    $state[$channel] = self::FAILED;
                }
            }
        }

        $written = $this->record($mirror, $uuid, $cycle, $signal, $round, $state, $backoff, $now, $results);

        if (! $written) {
            $report['lost']++;
            Degradation::error(self::STATE_LOST, "The notification state of an incident was not written: a later tick claimed round {$round} + 1 meanwhile.");
        }

        // A round that tried no send (every channel suppressed, skipped or unsent) takes no row of the run.
        return array_filter($results, static fn (string $outcome): bool => $outcome !== self::UNSENT) === [];
    }

    /**
     * Step 6: creates the missing cooldown rows of $wanted, locks them in
     * key order, and decides each channel: suppressed (`held + 1`) or sent
     * (the cooldown reserved, `held` taken).
     *
     * @param  array<string, string>  $wanted  channel => key
     * @return array<string, array{send: bool, more: int, reserved: string, previous: string}>
     */
    private function decide(Connection $mirror, StuckJobEvent $event, array $wanted): array
    {
        Hooks::reach(self::BEFORE_LOCK);
        $ordered = $wanted;
        // The inserts in key order too (MySQL's INSERT IGNORE takes a shared lock on each duplicate).
        asort($ordered);

        foreach ($ordered as $channel => $id) {
            // A lock on a missing row holds nothing: the rows exist before the transaction. One row
            // per statement, so a value that one channel cannot store blocks no other channel.
            $inserted = $this->watcher->attempt('notification:'.self::cutChannel($channel), static function () use ($mirror, $event, $channel, $id): void {
                $mirror->table(self::COOLDOWNS)->insertOrIgnore([
                    'key' => $id, 'connection' => $event->connection, 'queue' => $event->queue, 'signal' => $event->signal,
                    // The digest is the identity: the stored name is for display only, cut to the column.
                    'channel' => self::cutChannel($channel),
                    'cooldown_until' => Clock::toDecimal(0.0), 'held' => 0, 'summary_tries' => 0,
                ]);
            });

            if (! $inserted->succeeded()) {
                unset($wanted[$channel]);
            }
        }

        if ($wanted === []) {
            return [];
        }

        return $this->transactions->run($mirror, static function (Connection $tx) use ($wanted): array {
            $keys = array_values(array_unique($wanted));
            sort($keys);
            $locked = $tx->table(self::COOLDOWNS)->whereIn('key', $keys)->orderBy('key')->lockForUpdate()->get(['key', 'cooldown_until', 'held'])->keyBy('key')->all();
            Hooks::reach(self::AFTER_LOCK);
            $now = Clock::now();
            $decided = [];
            $reserved = [];

            foreach ($wanted as $channel => $id) {
                $row = $locked[$id] ?? null;

                if (! $row instanceof stdClass) {
                    continue;
                }

                $until = Clock::stored($row->cooldown_until);

                if ($until > $now || isset($reserved[$id])) {
                    $tx->table(self::COOLDOWNS)->where('key', $id)->update(['held' => (int) $row->held + 1]);
                    $row->held = (int) $row->held + 1;
                    $decided[$channel] = ['send' => false, 'more' => 0, 'reserved' => '', 'previous' => ''];

                    continue;
                }

                $reservedUntil = Clock::toDecimal($now + self::COOLDOWN_SECONDS);
                $tx->table(self::COOLDOWNS)->where('key', $id)->update(['cooldown_until' => $reservedUntil, 'held' => 0]);
                $reserved[$id] = true;
                $decided[$channel] = ['send' => true, 'more' => (int) $row->held, 'reserved' => $reservedUntil, 'previous' => Clock::toDecimal($until)];
            }

            return $decided;
        });
    }

    /**
     * Step 8: gives the reservation and the count of each failed or unsent
     * send back, on the cooldown rows locked in key order, guarded by the
     * reserved time (a later reservation of the key stays).
     *
     * @param  list<string>  $returned
     * @param  array<string, string>  $wanted
     * @param  array<string, array{send: bool, more: int, reserved: string, previous: string}>  $decided
     */
    private function giveBack(Connection $mirror, array $returned, array $wanted, array $decided): void
    {
        $this->transactions->run($mirror, static function (Connection $tx) use ($returned, $wanted, $decided): void {
            $keys = [];

            foreach ($returned as $channel) {
                $keys[] = $wanted[$channel];
            }

            sort($keys);
            $locked = $tx->table(self::COOLDOWNS)->whereIn('key', $keys)->orderBy('key')->lockForUpdate()->get(['key', 'cooldown_until', 'held'])->keyBy('key')->all();

            foreach ($returned as $channel) {
                $decision = $decided[$channel];
                $row = $locked[$wanted[$channel]] ?? null;

                if ($row instanceof stdClass && Clock::toDecimal((float) $row->cooldown_until) === $decision['reserved']) {
                    $tx->table(self::COOLDOWNS)->where('key', $wanted[$channel])->update(['cooldown_until' => $decision['previous'], 'held' => (int) $row->held + $decision['more']]);
                }
            }
        });
    }

    /**
     * Step 9: updates the channel backoffs and writes the incident's state.
     * The incident row is read FOR UPDATE last in the lock order, and the
     * state is written only while `notify_attempts` is still this round
     * (false otherwise: a later tick claimed the next round meanwhile). The
     * check reads the row: on MySQL an update reports the rows that it
     * changed, so an unchanged state would look like a miss.
     *
     * @param  array<string, string>  $state
     * @param  array<string, string>  $results
     */
    private function record(Connection $mirror, string $uuid, int $cycle, string $signal, int $round, array $state, ?int $backoff, float $now, array $results): bool
    {
        $failed = array_keys(array_filter($results, static fn (string $outcome): bool => $outcome === self::FAILED));
        $sent = array_keys(array_filter($results, static fn (string $outcome): bool => $outcome === self::SENT));
        $retrying = array_keys(array_filter($state, static fn (string $outcome): bool => self::isRetry($outcome)));
        $touchDown = $failed !== [] || $sent !== [];

        if ($touchDown) {
            $mirror->table(self::SETTINGS)->insertOrIgnore(['key' => self::KEYS, 'value' => self::EMPTY]);
        }

        return $this->transactions->run($mirror, function (Connection $tx) use ($uuid, $cycle, $signal, $round, $state, $backoff, $now, $failed, $sent, $retrying, $touchDown): bool {
            $down = ['down' => []];

            if ($touchDown) {
                $down = $this->loadDown($tx, true);

                foreach ($failed as $channel) {
                    $down['down'][$channel] = self::downEntry($down['down'][$channel] ?? null);
                }

                foreach ($sent as $channel) {
                    unset($down['down'][$channel]);
                }

                $tx->table(self::SETTINGS)->upsert([['key' => self::KEYS, 'value' => json_encode(['down' => (object) $down['down']], self::JSON)]], ['key'], ['value']);
            }

            $incident = $tx->table(self::TABLE)->where('job_uuid', $uuid)->where('cycle', $cycle)->where('signal', $signal);
            $current = (clone $incident)->lockForUpdate()->value('notify_attempts');

            if ($current === null || (int) $current !== $round) {
                return false;
            }

            $next = null;

            if ($retrying !== [] && $backoff !== null) {
                $next = max($now + $backoff, self::downUntil($down['down'], $retrying, $now) ?? 0.0);
            }

            $incident->update([
                'notified' => json_encode((object) $state, self::JSON),
                'notify_next_at' => $next === null ? null : Clock::toDecimal($next),
            ]);

            return true;
        });
    }

    /**
     * A channel name for the `channel` column: at most CHANNEL_CHARACTERS
     * characters of valid UTF-8 (the digest of the full name is the key).
     */
    public static function cutChannel(string $channel): string
    {
        return mb_substr(mb_scrub($channel, 'UTF-8'), 0, self::CHANNEL_CHARACTERS, 'UTF-8');
    }

    /**
     * The summaries of the cooldown rows whose cooldown ended with held-back
     * incidents (the class doc).
     *
     * @param  array<string, mixed>  $routes
     * @param  Closure(): bool  $over
     * @param  array{sent: int, failed: int, retried: int, suppressed: int, summaries: int, ended: int, pruned: int, lost: int}  $report
     */
    private function summaries(Connection $mirror, array $routes, Closure $over, array &$report): void
    {
        $candidates = $mirror->table(self::COOLDOWNS)
            ->where('cooldown_until', '<=', Clock::toDecimal(Clock::now()))
            ->where('held', '>', 0)
            ->orderBy('cooldown_until')
            ->orderBy('key')
            ->limit(self::SUMMARY_ROWS)
            ->get(['key', 'connection', 'queue', 'signal', 'channel'])
            ->all();
        $down = $this->down($mirror);

        foreach ($candidates as $candidate) {
            if ($over() || $this->watcher->breakerOpen()) {
                break;
            }

            $channel = (string) $candidate->channel;

            if (self::isDown($down, $channel, Clock::now())) {
                continue;
            }

            $id = (string) $candidate->key;

            if (! array_key_exists($channel, $routes)) {
                // Its route was removed: the count is dropped.
                $mirror->table(self::COOLDOWNS)->where('key', $id)->update(['held' => 0, 'summary_tries' => 0]);

                continue;
            }

            Hooks::reach(self::BEFORE_SUMMARY);
            // The claim: a guarded update on the locked row, so one tick sends the summary.
            $claim = $this->transactions->run($mirror, static function (Connection $tx) use ($id): ?array {
                $row = $tx->table(self::COOLDOWNS)->where('key', $id)->lockForUpdate()->first(['cooldown_until', 'held', 'summary_tries']);
                $now = Clock::now();

                if (! $row instanceof stdClass || (int) $row->held === 0 || Clock::stored($row->cooldown_until) > $now) {
                    return null;
                }

                $reserved = Clock::toDecimal($now + self::COOLDOWN_SECONDS);
                $tx->table(self::COOLDOWNS)->where('key', $id)->update(['cooldown_until' => $reserved, 'held' => 0]);

                return ['count' => (int) $row->held, 'tries' => (int) $row->summary_tries, 'reserved' => $reserved, 'previous' => Clock::toDecimal((float) $row->cooldown_until)];
            });

            if ($claim === null) {
                continue;
            }

            $outcome = $this->send(static fn (): Notification => new StuckJobsSummary((string) $candidate->connection, (string) $candidate->queue, (string) $candidate->signal, $claim['count']), $channel, $routes[$channel]);

            if ($outcome === self::SENT) {
                $report['summaries']++;
                $mirror->table(self::COOLDOWNS)->where('key', $id)->update(['summary_tries' => 0]);

                continue;
            }

            if ($outcome === self::SKIPPED) {
                continue;
            }

            $tries = $claim['tries'] + 1;
            $mirror->table(self::SETTINGS)->insertOrIgnore(['key' => self::KEYS, 'value' => self::EMPTY]);
            // The channel is down for the rest of this run too: the later summaries of the run
            // skip it, so one outage spends one try of one summary per backoff.
            $down = $this->transactions->run($mirror, function (Connection $tx) use ($id, $claim, $tries, $channel): array {
                $row = $tx->table(self::COOLDOWNS)->where('key', $id)->lockForUpdate()->first(['cooldown_until', 'held']);

                // The count back, unless this was its last try; guarded by the reserved time.
                if ($row instanceof stdClass && Clock::toDecimal((float) $row->cooldown_until) === $claim['reserved']) {
                    $tx->table(self::COOLDOWNS)->where('key', $id)->update([
                        'cooldown_until' => $claim['previous'],
                        'held' => $tries >= self::SUMMARY_TRIES ? (int) $row->held : (int) $row->held + $claim['count'],
                        'summary_tries' => $tries >= self::SUMMARY_TRIES ? 0 : $tries,
                    ]);
                }
                $down = $this->loadDown($tx, true);
                $down['down'][$channel] = self::downEntry($down['down'][$channel] ?? null);
                $tx->table(self::SETTINGS)->upsert([['key' => self::KEYS, 'value' => json_encode(['down' => (object) $down['down']], self::JSON)]], ['key'], ['value']);

                return $down['down'];
            });
        }
    }

    /**
     * Deletes at most PRUNE_ROWS cooldown rows whose cooldown ended with
     * nothing held (the `expiry` index).
     */
    private function prune(Connection $mirror): int
    {
        $now = Clock::toDecimal(Clock::now());
        $keys = $mirror->table(self::COOLDOWNS)
            ->where('cooldown_until', '<=', $now)
            ->where('held', 0)
            ->orderBy('cooldown_until')
            ->orderBy('key')
            ->limit(self::PRUNE_ROWS)
            ->pluck('key')
            ->all();
        $pruned = 0;
        // A row that another tick locks is left for a later prune (SKIP LOCKED where the engine has
        // it; SQLite's BEGIN IMMEDIATE serializes the writers anyway).
        $lock = in_array($mirror->getDriverName(), ['mysql', 'pgsql'], true) ? 'for update skip locked' : true;
        sort($keys);

        foreach (array_chunk($keys, 250) as $chunk) {
            $pruned += $this->transactions->run($mirror, static function (Connection $tx) use ($chunk, $now, $lock): int {
                // The rows locked in key order, then deleted; guarded again: a row reserved or counted meanwhile stays.
                $locked = $tx->table(self::COOLDOWNS)->whereIn('key', $chunk)->where('cooldown_until', '<=', $now)->where('held', 0)
                    ->orderBy('key')->lock($lock)->pluck('key')->all();

                if ($locked === []) {
                    return 0;
                }

                // The table by its literal name (self::COOLDOWNS), as the delete scan of the tests reads package deletes.
                return $tx->table('orrery_notify_cooldowns')->whereIn('key', $locked)->where('cooldown_until', '<=', $now)->where('held', 0)->delete();
            });
        }

        return $pruned;
    }

    /**
     * The next down entry after a failure: 60 s after the first, 300 s after
     * a later consecutive one.
     *
     * @param  array{until: float, n: int}|null  $current
     * @return array{until: float, n: int}
     */
    private static function downEntry(?array $current): array
    {
        $failures = ($current['n'] ?? 0) + 1;

        return ['until' => Clock::now() + self::DOWN_BACKOFF[min($failures, 2)], 'n' => $failures];
    }

    /**
     * @param  array<string, array{until: float, n: int}>  $down
     */
    private static function isDown(array $down, string $channel, float $now): bool
    {
        return ($down[$channel]['until'] ?? 0.0) > $now;
    }

    /**
     * The latest end of a backoff among the down channels of $channels, or
     * null when none is down.
     *
     * @param  array<string, array{until: float, n: int}>  $down
     * @param  list<string>  $channels
     */
    private static function downUntil(array $down, array $channels, float $now): ?float
    {
        $until = null;

        foreach ($channels as $channel) {
            if (self::isDown($down, $channel, $now)) {
                $until = max($until ?? 0.0, $down[$channel]['until']);
            }
        }

        return $until;
    }

    /**
     * Sends one channel inside its own watched operation, the notification's
     * build and the driver check included: SENT, SKIPPED (no driver) or
     * FAILED.
     *
     * @param  Closure(): Notification  $build
     */
    private function send(Closure $build, string $channel, mixed $route): string
    {
        $result = $this->watcher->attempt('notification:'.$channel, function () use ($build, $channel, $route): string {
            if (! self::channelAvailable($channel)) {
                $this->warnMissing($channel);

                return self::SKIPPED;
            }

            $notification = $build();
            $notifiable = (new AnonymousNotifiable)->route($channel, $route);
            Container::getInstance()->make(Dispatcher::class)->sendNow($notifiable, $notification, [$channel]);

            return self::SENT;
        });

        return $result->succeeded() && is_string($result->value()) ? $result->value() : self::FAILED;
    }

    /**
     * False when the notification manager has no driver for the channel. A
     * test fake of the manager accepts every channel. Any other error of the
     * driver's build gives true, so this check, which may run outside the
     * channel's watched operation, never throws.
     */
    private static function channelAvailable(string $channel): bool
    {
        /** @var mixed $manager */
        $manager = Container::getInstance()->make(Dispatcher::class);

        if (! $manager instanceof ChannelManager) {
            return true;
        }

        try {
            $manager->driver($channel);
        } catch (InvalidArgumentException) {
            return false;
        } catch (Throwable) {
            // A driver that cannot be built (a creator that throws, a dependency that cannot be
            // resolved): the watched send builds it again and fails there, as that channel's failed try.
            return true;
        }

        return true;
    }

    /**
     * One warning a day per channel. A marker store that fails gives the
     * warning again, never an error.
     */
    private function warnMissing(string $channel): void
    {
        try {
            $first = NotificationState::current()->add('orrery:notification:stuck-missing:'.sha1($channel), self::WARNING_SECONDS);
        } catch (Throwable) {
            $first = true;
        }

        if ($first) {
            Log::warning("Orrery skipped the stuck-job notification channel [{$channel}]: the notification manager has no driver for it. Install its package (for example laravel/slack-notification-channel or laravel/vonage-notification-channel), or remove its route.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function routes(): array
    {
        return array_filter([
            'slack' => Orrery::$slackWebhookUrl,
            'vonage' => Orrery::$smsNumber,
            'mail' => Orrery::$email,
        ], static fn (mixed $route): bool => $route !== null);
    }

    /**
     * @param  array<string, mixed>  $routes
     */
    private static function notifiable(array $routes): AnonymousNotifiable
    {
        $notifiable = new AnonymousNotifiable;

        foreach ($routes as $channel => $route) {
            $notifiable->route($channel, $route);
        }

        return $notifiable;
    }

    /**
     * The channel states of a stored `notified`.
     *
     * @return array<string, string>
     */
    private static function state(mixed $stored): array
    {
        $value = is_string($stored) ? json_decode($stored, true) : null;
        $state = [];

        foreach (is_array($value) ? $value : [] as $channel => $outcome) {
            if (is_string($channel) && is_string($outcome)) {
                $state[$channel] = $outcome;
            }
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $evidence): array
    {
        $value = is_string($evidence) ? json_decode($evidence, true) : null;
        $facts = [];

        foreach (is_array($value) ? $value : ['truncated' => true] as $key => $fact) {
            $facts[(string) $key] = $fact;
        }

        return $facts;
    }

    /**
     * A channel state `retry:<tries>`: not done, after that many real tries.
     */
    public static function retry(int $tries): string
    {
        return self::RETRY.':'.$tries;
    }

    /**
     * Whether a channel state waits for a later round.
     */
    public static function isRetry(?string $state): bool
    {
        return $state !== null && ($state === self::RETRY || str_starts_with($state, self::RETRY.':'));
    }

    /**
     * The real tries of a channel state (0 for a channel never tried).
     */
    public static function tries(?string $state): int
    {
        return self::isRetry($state) && preg_match('/^'.self::RETRY.':(\d+)$/', (string) $state, $match) === 1 ? (int) $match[1] : 0;
    }

    /**
     * The channel backoffs, without a lock.
     *
     * @return array<string, array{until: float, n: int}>
     */
    private function down(Connection $connection): array
    {
        return $this->loadDown($connection)['down'];
    }

    /**
     * The channel backoffs of KEYS; with $lock, read FOR UPDATE inside the
     * caller's transaction.
     *
     * @return array{down: array<string, array{until: float, n: int}>}
     */
    private function loadDown(Connection $connection, bool $lock = false): array
    {
        $query = $connection->table(self::SETTINGS)->where('key', self::KEYS);
        $stored = ($lock ? $query->lockForUpdate() : $query)->value('value');
        $value = is_string($stored) ? json_decode($stored, true) : null;
        $down = [];

        foreach (is_array($value['down'] ?? null) ? $value['down'] : [] as $channel => $entry) {
            if (is_string($channel) && is_array($entry) && is_numeric($entry['until'] ?? null) && is_int($entry['n'] ?? null)) {
                $down[$channel] = ['until' => (float) $entry['until'], 'n' => $entry['n']];
            }
        }

        return ['down' => $down];
    }
}
