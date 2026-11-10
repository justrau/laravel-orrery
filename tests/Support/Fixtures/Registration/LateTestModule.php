<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Registration;

/**
 * A test module that ModulesTest adds after every listed module. It logs
 * into EarlyTestModule::$log.
 */
final class LateTestModule extends EarlyTestModule
{
    protected static function label(): string
    {
        return 'Late';
    }
}
