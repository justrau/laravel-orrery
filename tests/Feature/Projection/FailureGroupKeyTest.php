<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection;

use Justrau\Orrery\Failures\GroupKey;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Tests\Feature\Projection\Jobs\CanaryJob;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;

/**
 * The failure-group key is an HMAC keyed by the application key:
 * the key appears in URLs and responses, and an
 * unsalted hash of a first line with a low-entropy secret could be brute
 * forced.
 */
final class FailureGroupKeyTest extends TestCase
{
    private const APP_KEY = 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.key', self::APP_KEY);
    }

    /**
     * Rejects: the unsalted sha256 of the canonical form, an HMAC without
     * the domain label, or a key that ignores the application key.
     */
    public function test_the_key_is_an_hmac_of_the_canonical_form(): void
    {
        $canonical = GroupKey::canonical('RuntimeException', 'pin 1234 rejected', 'App\\Jobs\\Sync');
        $key = GroupKey::of('RuntimeException', "pin 1234 rejected\nstack", 'App\\Jobs\\Sync');

        self::assertSame(hash_hmac('sha256', $canonical, 'orrery-failure-key|'.self::APP_KEY), $key);
        self::assertNotSame(hash('sha256', $canonical), $key);
        self::assertNotSame(hash_hmac('sha256', $canonical, self::APP_KEY), $key);
        self::assertTrue(GroupKey::valid($key));
        self::assertSame($key, GroupKey::keyed($canonical, self::APP_KEY));

        $this->app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        self::assertNotSame($key, GroupKey::of('RuntimeException', 'pin 1234 rejected', 'App\\Jobs\\Sync'), 'A key rotation changes the key.');
    }

    /**
     * Rejects: a stored key computed without the HMAC (JobStore writes the
     * key with every failure).
     */
    public function test_a_failure_stores_the_hmac_key(): void
    {
        $this->app['queue']->connection('database')->push(new CanaryJob(null, true));
        $this->work();

        $row = $this->mirror()->table(JobStore::TABLE)->first(['status', 'name', 'exception_class', 'exception_message', 'failure_key']);
        self::assertNotNull($row);
        self::assertSame('failed', $row->status);
        self::assertSame(RuntimeException::class, $row->exception_class);

        $canonical = GroupKey::canonical($row->exception_class, $row->exception_message, $row->name);
        self::assertSame(hash_hmac('sha256', $canonical, 'orrery-failure-key|'.self::APP_KEY), $row->failure_key);
    }
}
