<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;

/**
 * GET /api/health `app_key_missing` and `bulk_config`:
 * an empty app.key refuses every bulk forget, and an invalid
 * orrery.bulk.forget_max refuses every bulk forget; the footer warns from
 * both.
 */
final class HealthBulkTest extends ControllerTest
{
    /**
     * Rejects: a health answer that hides an empty app.key (the bulk forget
     * would be refused without a warning), and one that warns while a key is
     * set.
     */
    public function test_app_key_missing_follows_the_app_key(): void
    {
        $this->app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
        self::assertFalse($this->health()['app_key_missing']);

        foreach (['', null] as $empty) {
            $this->app['config']->set('app.key', $empty);
            self::assertTrue($this->health()['app_key_missing']);
        }
    }

    /**
     * Rejects: a bulk_config that calls an out-of-range or malformed
     * forget_max valid, a missing key (a configuration published before 2026-11)
     * that is invalid instead of the default, and a limit other than
     * 1..100,000.
     */
    public function test_bulk_config_validates_forget_max(): void
    {
        foreach ([[10_000, 10_000], [1, 1], [100_000, 100_000], ['250', 250]] as [$value, $effective]) {
            $this->app['config']->set('orrery.bulk.forget_max', $value);
            self::assertSame(['valid' => true, 'forget_max' => $effective], $this->health()['bulk_config']);
        }

        foreach ([0, 100_001, -5, 1.5, '1e3', 'many', [5]] as $invalid) {
            $this->app['config']->set('orrery.bulk.forget_max', $invalid);
            self::assertSame(['valid' => false, 'forget_max' => null], $this->health()['bulk_config'], var_export($invalid, true));
        }

        $this->app['config']->set('orrery.bulk', []);
        self::assertSame(['valid' => true, 'forget_max' => 10_000], $this->health()['bulk_config'], 'a configuration without the key takes the default');
    }

    /**
     * @return array<string, mixed>
     */
    private function health(): array
    {
        $health = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json();
        self::assertIsArray($health);

        return $health;
    }
}
