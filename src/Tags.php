<?php

namespace Justrau\Orrery;

use Closure;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Collection;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

class Tags
{
    protected static ?object $event = null;

    /**
     * The tags of a job, as its tags() methods return them. The values are
     * not deduplicated and not validated here: a loose unique() would merge
     * '01' and '1', or let an invalid true remove a valid tag. The caller
     * normalizes and deduplicates them by the normalized string
     * (Observation\TagNormalizer::normalizeList()).
     *
     * Tags classification accepts job and event objects; a direct class-name
     * string whose class declares tags() is outside the supported
     * classification API.
     *
     * @return array<int, mixed>
     */
    public static function for(mixed $job): array
    {
        if ($tags = static::extractExplicitTags($job)) {
            return $tags;
        }

        return static::modelsFor(static::targetsFor($job))
            ->map(fn ($model) => get_class($model).':'.$model->getKey())
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    public static function extractExplicitTags(mixed $job): array
    {
        return $job instanceof CallQueuedListener
            ? static::tagsForListener($job)
            : static::explicitTags(static::targetsFor($job));
    }

    /**
     * @return array<int, mixed>
     */
    protected static function tagsForListener(mixed $job): array
    {
        $event = static::extractEvent($job);
        $previous = static::$event;

        static::setEvent($event);

        try {
            return collect([static::extractListener($job), $event])
                ->map(fn ($target) => static::for($target))
                ->collapse()
                ->values()
                ->all();
        } finally {
            // A tags() method that throws must not leave its event behind.
            static::$event = $previous;
        }
    }

    /**
     * Runs $work with a fresh Tags state, and restores the state that was
     * set before in finally. A classification that runs inside
     * another classification (a job dispatched from a tags() method) so
     * never sees the outer event, and the outer classification gets its
     * own state back.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function isolated(Closure $work): mixed
    {
        $previous = static::$event;
        static::$event = null;

        try {
            return $work();
        } finally {
            static::$event = $previous;
        }
    }

    /**
     * Removes the static state (the test reset of HttpModule).
     */
    public static function flushState(): void
    {
        static::flushEventState();
    }

    /**
     * @param  array<int, mixed>  $jobs
     * @return array<int, mixed>
     */
    protected static function explicitTags(array $jobs): array
    {
        return collect($jobs)
            ->map(static function (mixed $job): mixed {
                if (! method_exists($job, 'tags')) {
                    return [];
                }

                // targetsFor() gives the job object or an object that it holds.
                assert(is_object($job));

                return $job->tags(static::$event);
            })
            ->collapse()
            ->values()
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    public static function targetsFor(mixed $job): array
    {
        return match (true) {
            $job instanceof BroadcastEvent => [$job->event],
            $job instanceof CallQueuedListener => [static::extractEvent($job)],
            $job instanceof SendQueuedMailable => [$job->mailable],
            $job instanceof SendQueuedNotifications => [$job->notification],
            default => [$job],
        };
    }

    /**
     * @param  array<int, mixed>  $targets
     * @return Collection<int, Model>
     */
    public static function modelsFor(array $targets): Collection
    {
        $models = [];

        foreach ($targets as $target) {
            $models[] = collect((new ReflectionClass($target))->getProperties())
                ->map(function ($property) use ($target) {
                    if (PHP_VERSION_ID < 80500) {
                        $property->setAccessible(true);
                    }

                    $value = static::getValue($property, $target);

                    if ($value instanceof Model) {
                        return [$value];
                    } elseif ($value instanceof EloquentCollection) {
                        return $value->all();
                    }

                    return null;
                })
                ->collapse()
                ->filter()
                ->all();
        }

        // No unique(): it compares the models with ==, which walks their loaded relations (a
        // relation cycle is a fatal error). The tag strings are deduplicated after the normalizer.
        return collect($models)->collapse();
    }

    protected static function getValue(ReflectionProperty $property, object $target): mixed
    {
        if (! $property->isInitialized($target)) {
            return null;
        }

        return $property->getValue($target);
    }

    protected static function extractListener(mixed $job): object
    {
        return (new ReflectionClass($job->class))->newInstanceWithoutConstructor();
    }

    protected static function extractEvent(mixed $job): object
    {
        return isset($job->data[0]) && is_object($job->data[0])
            ? $job->data[0]
            : new stdClass;
    }

    protected static function setEvent(object $event): void
    {
        static::$event = $event;
    }

    protected static function flushEventState(): void
    {
        static::$event = null;
    }
}
