<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection;

use Justrau\Orrery\Http\Projection\JobProjection;
use Justrau\Orrery\Http\Projection\Redactor;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;

/**
 * An application that published `config/orrery.php` before the UI rebuild:
 * `mergeConfigFrom()` merges only the top level, so the new
 * nested keys must take their defaults in the code. The fixture is the
 * configuration file of commit 038449a.
 */
final class PreD8ConfigurationTest extends ControllerTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $published = require __DIR__.'/fixtures/orrery-pre-d8.php';
        self::assertIsArray($published);
        self::assertArrayNotHasKey('redact', $published, 'The fixture predates the redaction keys.');
        $config = $app['config'];
        $harness = ['database' => $config->get('orrery.database'), 'pooled' => $config->get('orrery.sessions.pooled')];
        $config->set('orrery', array_merge($config->get('orrery', []), $published));

        // The test environment owns the package connection and the pooled
        // mode (ORRERY_TEST_MIRROR, ORRERY_TEST_PG_POOLED): the fixture's
        // env() defaults would point the package at another database, and
        // the harness refuses the identity.
        $config->set('orrery.database', $harness['database']);
        $config->set('orrery.sessions.pooled', $harness['pooled']);
    }

    /**
     * Rejects: a reader without its default (for example a missing
     * redact.defaults read as false), or a boot that fails on the old file.
     */
    public function test_a_pre_d8_configuration_boots_with_the_defaults(): void
    {
        $redactor = $this->app->make(Redactor::class);

        self::assertTrue($redactor->matchesKey('db_password'));
        self::assertSame('Login password=[redacted]', $this->app->make(JobProjection::class)->message('Login password=canary-pre-d8'));
        self::assertSame(['valid' => true, 'errors' => []], $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json('redaction'));

        // The published file without a `redact` block at all.
        $this->app['config']->set('orrery', array_diff_key((array) $this->app['config']->get('orrery'), ['redact' => true]));
        self::assertNull($this->app['config']->get('orrery.redact'));
        self::assertTrue($redactor->matchesKey('api_key'));
        self::assertSame(['valid' => true, 'errors' => []], $redactor->status());
    }
}
