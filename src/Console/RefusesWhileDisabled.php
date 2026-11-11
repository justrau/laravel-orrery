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
        if (! OffSwitch::enabled()) {
            return $this->whileDisabled();
        }

        return parent::execute($input, $output);
    }

    /**
     * The answer while the package is off: a refusal and exit 1. The tick
     * overrides it (a skip and exit 0), so a self-scheduled tick does not
     * fail every minute.
     */
    protected function whileDisabled(): int
    {
        $this->components->error(OffSwitch::MESSAGE.' '.$this->getName().' does nothing until you set ORRERY_ENABLED=true, refresh the configuration cache and restart the workers.');

        return self::FAILURE;
    }
}
