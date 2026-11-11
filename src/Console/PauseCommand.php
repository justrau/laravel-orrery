<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Justrau\Orrery\Control\ControlTarget;
use Justrau\Orrery\Control\QueuePauseRecordFailed;
use Justrau\Orrery\Control\SignalState;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'orrery:pause')]
class PauseCommand extends Command
{
    use RefusesWhileDisabled;

    /** The actor that a command stores in orrery_signals.issued_by. */
    public const ACTOR = 'cli';

    /** @var string */
    protected $signature = 'orrery:pause {--connection= : Restrict pause to one queue connection}
                                              {--queue= : Restrict pause to one queue (requires --connection)}';

    /** @var string */
    protected $description = 'Pause job reservation for workers in the given scope (default: global); a queue pause uses Laravel\'s queue:pause.';

    public function handle(SignalState $signals): int
    {
        /** @var string|null $connection */
        $connection = $this->option('connection');
        /** @var string|null $queue */
        $queue = $this->option('queue');

        try {
            $target = ControlTarget::fromOptions($connection, $queue);
        } catch (InvalidArgumentException $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        [$scope, $value, $label] = [$target->scope, $target->value, $target->describe()];

        try {
            $signals->pause($scope, $value, self::ACTOR);
        } catch (QueuePauseRecordFailed $error) {
            // Laravel's part happened: say what holds now, not only that the command failed.
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        $this->components->info($scope === 'queue'
            ? "Paused {$label} with Laravel's queue pause. Workers skip it on their next poll; it needs a cache store that every worker shares."
            : "Paused {$label}. Workers will skip job reservation on their next poll.");

        return self::SUCCESS;
    }
}
