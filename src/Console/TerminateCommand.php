<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Justrau\Orrery\Control\ControlTarget;
use Justrau\Orrery\Control\SignalState;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'orrery:terminate')]
class TerminateCommand extends Command
{
    use RefusesWhileDisabled;

    /** The actor that a command stores in orrery_signals.issued_by. */
    public const ACTOR = 'cli';

    /** @var string */
    protected $signature = 'orrery:terminate {--connection= : Restrict terminate to one queue connection}
                                                  {--queue= : Restrict terminate to one queue (requires --connection)}';

    /** @var string */
    protected $description = 'Tell matching workers to exit cleanly after their current job. Used for deploys: process manager respawns them with fresh code.';

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

        $signals->terminate($scope, $value, issuedBy: self::ACTOR);

        // A worker sets its stop latch and shouldQuit (Control\StopLatch); it sends SIGTERM only when no Worker is bound.
        $this->components->info("Termination signal issued for $label. Each matching worker that started before it finishes its current job and then exits; your process manager starts it again.");

        return self::SUCCESS;
    }
}
