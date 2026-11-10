<?php

namespace Justrau\Orrery\Control;

use Illuminate\Container\Container;
use InvalidArgumentException;
use Justrau\Orrery\Monitoring\NativePause;

/**
 * The target of a pause, continue or terminate, shared by
 * ControlController and the orrery:pause, orrery:continue and
 * orrery:terminate commands.
 *
 * - `global`: the scope value ''.
 * - `connection`: a `queue.connections` entry with the driver `database`.
 * - `queue`: such a connection and a queue name. An unknown queue name is
 *   allowed: a pause can come before the first job.
 * - The scope value must fit orrery_signals.scope_value (MAX_VALUE
 *   characters).
 * - The queue name NativePause::PROBE_QUEUE is reserved.
 * - A queue of a connection whose name holds ":" is refused: the stored
 *   `connection:queue` value would be ambiguous.
 *
 * resolve() throws InvalidArgumentException with the message for the
 * operator: the controller answers 422, a command exits 1.
 */
final class ControlTarget
{
    /** The exception code of a refused connection; any other refusal is about the queue or the scope. */
    public const REFUSED_CONNECTION = 1;

    /** The length of orrery_signals.scope_value. */
    public const MAX_VALUE = 191;

    private function __construct(
        public readonly string $scope,
        public readonly string $value,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function resolve(string $scope, ?string $connection, ?string $queue): self
    {
        if ($scope === 'global') {
            return new self('global', '');
        }

        if ($scope !== 'connection' && $scope !== 'queue') {
            throw new InvalidArgumentException("The control scope [{$scope}] is not global, connection or queue.");
        }

        if ($connection === null || $connection === '') {
            throw new InvalidArgumentException("The {$scope} scope requires a connection.", self::REFUSED_CONNECTION);
        }

        if ($scope === 'queue' && ($queue === null || $queue === '')) {
            throw new InvalidArgumentException('The queue scope requires a connection and a queue.');
        }

        // Laravel reports this name as paused only through its global pause
        // (NativePause): a pause of it would fake that pause everywhere.
        if ($scope === 'queue' && $queue === NativePause::PROBE_QUEUE) {
            throw new InvalidArgumentException(sprintf('The queue name "%s" is reserved by Orrery.', NativePause::PROBE_QUEUE));
        }

        $driver = Container::getInstance()->make('config')->get("queue.connections.{$connection}.driver");

        if ($driver !== 'database') {
            throw new InvalidArgumentException("The queue connection [{$connection}] does not use the database driver.", self::REFUSED_CONNECTION);
        }

        if ($scope === 'queue' && str_contains($connection, ':')) {
            // `connection:queue` would read back as another target: "a:b" + "q" and "a" + "b:q" are one value.
            throw new InvalidArgumentException("The queue connection [{$connection}] has a \":\" in its name, so a target of one of its queues would be ambiguous. Use the connection scope.", self::REFUSED_CONNECTION);
        }

        $value = $scope === 'queue' ? "{$connection}:{$queue}" : $connection;

        if (mb_strlen($value, 'UTF-8') > self::MAX_VALUE) {
            throw new InvalidArgumentException(sprintf('The control target "%s…" is longer than %d characters.', mb_substr($value, 0, 40, 'UTF-8'), self::MAX_VALUE));
        }

        return new self($scope, $value);
    }

    /**
     * The target of the --connection and --queue options of a command: a
     * queue scope with both, a connection scope with --connection, else
     * global.
     *
     * @throws InvalidArgumentException
     */
    public static function fromOptions(?string $connection, ?string $queue): self
    {
        if ($queue !== null && $connection === null) {
            throw new InvalidArgumentException('--queue requires --connection.');
        }

        return self::resolve(match (true) {
            $queue !== null => 'queue',
            $connection !== null => 'connection',
            default => 'global',
        }, $connection, $queue);
    }

    /**
     * The scope and value as the command output names them.
     */
    public static function label(string $scope, string $value): string
    {
        return match ($scope) {
            'queue' => "queue \"{$value}\"",
            'connection' => "connection \"{$value}\"",
            default => 'all workers (global)',
        };
    }

    public function describe(): string
    {
        return self::label($this->scope, $this->value);
    }
}
