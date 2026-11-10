<?php

namespace Justrau\Orrery\Observation;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Container\Container;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Justrau\Orrery\Contracts\Silenced;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tags;

/**
 * The payload callback of the package.
 *
 * ObservationModule registers it with Queue::createPayloadUsing(), inside
 * Watcher::run('payload.classify', …, []). Laravel calls it in
 * createPayload() for push, later and bulk, in both swap modes, before it
 * serializes the job. So the payload that Laravel inserts and the payload of
 * JobQueued are the same string, with the same generation.
 *
 * The payload keys (the lifecycle reads them):
 * - `type`: job, broadcast, event, mail or notification;
 * - `tags`: the normalized tags (TagNormalizer), and `tagsTruncated`;
 * - `silenced`: the configured classes, the Silenced contract, or a
 *   configured silenced tag;
 * - `pushedAt`: the push time as a decimal string (Clock::toDecimal());
 * - `orreryGen`: a new generation (Generation::new()).
 * A string job gets only `type` (job), `silenced` (its exact handler string
 * is listed in `orrery.silenced`), `pushedAt` and `orreryGen`. A connection
 * whose driver is not `database` gets no keys.
 */
final class PayloadClassifier
{
    public const DEFAULT_MAX_TAGS = 50;

    /**
     * @param  array<mixed>  $payload
     * @return array{type?: string, tags?: list<string>, tagsTruncated?: bool, silenced?: bool, pushedAt?: string, orreryGen?: string}
     */
    public function __invoke(?string $connectionName, ?string $queue, array $payload): array
    {
        if (! DatabaseDriverFilter::isDatabase($connectionName)) {
            return [];
        }

        $data = $payload['data'] ?? null;
        $command = is_array($data) ? ($data['command'] ?? null) : null;

        // Tags keeps static state while it collects tags. A job that a
        // tags() method dispatches is classified with a fresh state, and
        // the state is reset in finally (Tags::isolated()).
        return Tags::isolated(function () use ($command, $payload): array {
            if (! is_object($command)) {
                return [
                    'type' => 'job',
                    'silenced' => $this->handlerSilenced($payload['job'] ?? null),
                    'pushedAt' => Clock::toDecimal(Clock::now()),
                    'orreryGen' => Generation::new(),
                ];
            }

            $tags = TagNormalizer::normalizeList(Tags::for($command), $this->maxTags());

            return [
                'type' => self::type($command),
                'tags' => $tags['tags'],
                'tagsTruncated' => $tags['truncated'],
                'silenced' => $this->silenced($command, $tags['tags']),
                'pushedAt' => Clock::toDecimal(Clock::now()),
                'orreryGen' => Generation::new(),
            ];
        });
    }

    /**
     * A string job is silenced only when its exact handler string (the
     * payload's `job`, for example `App\\Handlers\\X@handle`) is listed in
     * `orrery.silenced` (Horizon parity, by design). No class is
     * loaded or instantiated for it, so a listed class name that is only a
     * prefix of the handler never matches.
     */
    private function handlerSilenced(mixed $handler): bool
    {
        return is_string($handler) && in_array($handler, $this->config('orrery.silenced'), true);
    }

    private static function type(object $job): string
    {
        return match (true) {
            $job instanceof BroadcastEvent => 'broadcast',
            $job instanceof CallQueuedListener => 'event',
            $job instanceof SendQueuedMailable => 'mail',
            $job instanceof SendQueuedNotifications => 'notification',
            default => 'job',
        };
    }

    /**
     * @param  list<string>  $tags
     */
    private function silenced(object $job, array $tags): bool
    {
        $underlying = match (true) {
            $job instanceof BroadcastEvent => $job->event,
            $job instanceof CallQueuedListener => $job->class,
            $job instanceof SendQueuedMailable => $job->mailable,
            $job instanceof SendQueuedNotifications => $job->notification,
            default => $job,
        };

        $class = is_string($underlying) ? $underlying : (is_object($underlying) ? $underlying::class : null);

        if ($class === null) {
            return false;
        }

        $classes = $this->config('orrery.silenced');

        if (in_array($class, $classes, true) || is_a($class, Silenced::class, true)) {
            return true;
        }

        $silencedTags = TagNormalizer::normalizeList($this->config('orrery.silenced_tags'), PHP_INT_MAX)['tags'];

        return array_intersect($tags, $silencedTags) !== [];
    }

    private function maxTags(): int
    {
        $max = Container::getInstance()->make('config')->get('orrery.tags.max_per_job', self::DEFAULT_MAX_TAGS);

        return is_numeric($max) ? (int) $max : self::DEFAULT_MAX_TAGS;
    }

    /**
     * @return array<mixed>
     */
    private function config(string $key): array
    {
        $value = Container::getInstance()->make('config')->get($key, []);

        return is_array($value) ? $value : [];
    }
}
