<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Markers\Markers;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Records a deploy marker at now: the reliable deploy source of
 * the charts. Run it from the deploy script, on every server or once: a
 * marker with the same cleaned label in the last --window seconds (default
 * 600) means that another server recorded this deploy, so the command
 * records nothing. --window=0 always records. The label is
 * optional; it is cleaned (valid UTF-8, no control characters, at most 191
 * characters). A database error reaches the console kernel, so the command
 * exits non-zero when the marker was not decided.
 */
#[AsCommand(name: 'orrery:deployed')]
class DeployedCommand extends Command
{
    /** @var string */
    protected $signature = 'orrery:deployed {--label= : A short label for the deploy, for example a release tag}
                                                {--window=600 : Record nothing when a marker with this label is at most this many seconds old (0 always records)}';

    /** @var string */
    protected $description = 'Record a deploy marker on the Orrery charts (once per label within the window).';

    public function handle(Markers $markers): int
    {
        /** @var string|null $label */
        $label = $this->option('label');
        $window = $this->option('window');

        if (! is_string($window) || preg_match('/^[0-9]{1,9}$/D', $window) !== 1) {
            $this->components->error('--window must be a whole number of seconds, 0 or more.');

            return self::FAILURE;
        }

        $result = $markers->deploy($label, (int) $window);

        if ($result['recorded']) {
            $this->components->info('Recorded a deploy marker.');
        } else {
            $at = Carbon::createFromTimestamp($result['at'], date_default_timezone_get())->format('H:i:s');
            $this->components->info("A deploy marker with this label was recorded at {$at}; nothing recorded.");
        }

        return self::SUCCESS;
    }
}
