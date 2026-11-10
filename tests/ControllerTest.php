<?php

namespace Justrau\Orrery\Tests;

use Justrau\Orrery\Orrery;

/**
 * Controller tests: the view and the operate ability allow every request.
 * TestCase resets both callbacks after each test (Support\ResetsPackageState
 * and HttpModule::resetForTesting()).
 */
abstract class ControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');

        Orrery::auth(fn () => true);
        Orrery::operateUsing(fn () => true);
    }
}
