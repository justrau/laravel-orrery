<?php

namespace Justrau\Orrery\Console;

use Justrau\Orrery\Runtime\OffSwitch;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * An operator command that acts on the package storage or the workers:
 * while the package is off (Runtime\OffSwitch) it refuses with the reason
 * and exits 1, before Laravel resolves the arguments of handle(). The
 * commands of the removal procedure (orrery:continue, orrery:resolve),
 * orrery:install and orrery:workers do not use it.
 */
trait RefusesWhileDisabled
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (! OffSwitch::enabled($this->laravel->make('config'))) {
            $this->components->error(OffSwitch::MESSAGE.' '.$this->getName().' does nothing until you set ORRERY_ENABLED=true, refresh the configuration cache and restart the workers.');

            return self::FAILURE;
        }

        return parent::execute($input, $output);
    }
}
