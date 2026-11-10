<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Justrau\Orrery\Control\ControlTarget;
use Justrau\Orrery\Control\QueuePauseRecordFailed;
use Justrau\Orrery\Control\SignalState;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'orrery:continue')]
class ContinueCommand extends Command
{
    /** The actor that a command stores in orrery_signals.issued_by. */
    public const ACTOR = 'cli';

    /** @var string */
    protected $signature = 'orrery:continue {--connection= : Restrict continue to one queue connection}
                                                 {--queue= : Restrict continue to one queue (requires --connection)}';

    /** @var string */
    protected $description = 'Lift the pause of exactly the scope (default: global); a queue also resumes in Laravel (queue:continue).';

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
            $result = $signals->continue($scope, $value);
        } catch (QueuePauseRecordFailed $error) {
            // Laravel's part happened: say what holds now, not only that the command failed.
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }
        $lifted = match (true) {
            $result->removed > 0 => "Lifted the pause of {$label}.",
            $result->liftUnknown => "Resumed Laravel's pause of {$label}, if it was set (its state is ".($result->underLaravelGlobalPause() ? "hidden by Laravel's global pause" : 'unknown').').',
            default => "No pause of {$label} was set; nothing changed.",
        };
        $causes = $result->describeCauses();
        $still = $causes === [] ? null : ucfirst($label).' is still paused by '.self::sentence($causes).'.';
        $unknown = $result->stillPausedUnknown ? "Laravel's pause state could not be read, so a Laravel pause may still hold {$label}." : null;

        $this->components->info(implode(' ', array_filter([$lifted, $still, $unknown])));

        return self::SUCCESS;
    }

    /**
     * "A", "A and B", "A, B and C".
     *
     * @param  non-empty-list<string>  $parts
     */
    private static function sentence(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? $last : implode(', ', $parts).' and '.$last;
    }
}
