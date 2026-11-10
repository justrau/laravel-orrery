<?php

namespace Justrau\Orrery\Observation;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job;

/**
 * The driver filter of the package listeners: a listener
 * handles only the events of queue connections whose driver is `database`.
 */
final class DatabaseDriverFilter
{
    /**
     * Whether a queue connection uses the database driver. Null is the
     * default queue connection.
     */
    public static function isDatabase(?string $queueConnection): bool
    {
        $config = Container::getInstance()->make('config');
        $name = $queueConnection ?? $config->get('queue.default');

        return is_string($name) && $name !== '' && $config->get("queue.connections.{$name}.driver") === 'database';
    }

    /**
     * True for an event without a connectionName (a package event of no
     * connection, an event of another kind), else isDatabase() of the
     * event's connection (connectionOf()).
     */
    public static function accepts(object $event): bool
    {
        if (! property_exists($event, 'connectionName')) {
            return true;
        }

        $name = self::connectionOf($event);

        return ! is_string($name) || $name === '' || self::isDatabase($name);
    }

    /**
     * The queue connection of an event. Laravel's worker puts its
     * own connection argument on JobProcessing, JobProcessed,
     * JobExceptionOccurred, JobAttempted, Looping and WorkerStarting. On a
     * `failover` connection that is `failover`, while the job comes from the
     * first connection of its list. So the job's own connection name wins
     * when the event carries a job, and a `failover` name without a job maps
     * to its first connection (through nested failover lists; a cycle keeps
     * the name).
     */
    public static function connectionOf(object $event): mixed
    {
        $job = property_exists($event, 'job') ? $event->job : null;

        if ($job instanceof Job) {
            $name = (string) $job->getConnectionName();

            if ($name !== '') {
                return $name;
            }
        }

        $name = property_exists($event, 'connectionName') ? $event->connectionName : null;

        return is_string($name) ? self::primaryOf($name) : $name;
    }

    /**
     * The `failover` queue connections whose first connection (through
     * nested failover lists) is $connection: a worker on such a connection
     * pops from $connection, but asks for a queue pause under its own name.
     *
     * @return list<string>
     */
    public static function failoversOf(string $connection): array
    {
        $connections = Container::getInstance()->make('config')->get('queue.connections');
        $names = [];

        foreach (is_array($connections) ? $connections : [] as $name => $definition) {
            $name = (string) $name;

            if ($name !== $connection && is_array($definition) && ($definition['driver'] ?? null) === 'failover' && self::primaryOf($name) === $connection) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The first connection of a `failover` queue connection, followed through
     * nested failover lists; any other name unchanged.
     */
    public static function primaryOf(string $name): string
    {
        $config = Container::getInstance()->make('config');
        $seen = [];

        while (! isset($seen[$name]) && $config->get("queue.connections.{$name}.driver") === 'failover') {
            $seen[$name] = true;
            $first = ($config->get("queue.connections.{$name}.connections") ?? [])[0] ?? null;

            if (! is_string($first) || $first === '') {
                break;
            }

            $name = $first;
        }

        return $name;
    }
}
