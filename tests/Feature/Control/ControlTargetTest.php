<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Control;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Monitoring\NativePause;
use Justrau\Orrery\Tests\ControllerTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The control targets: the controller and the pause,
 * continue and terminate commands accept only a `database` queue
 * connection, and a scope value that fits orrery_signals.scope_value.
 */
final class ControlTargetTest extends ControllerTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function actions(): array
    {
        return ['pause' => ['pause'], 'continue' => ['continue'], 'terminate' => ['terminate']];
    }

    /**
     * Rejects: accepting `mysql` (a database connection, not a queue connection), or a queue connection of another driver.
     */
    #[DataProvider('actions')]
    public function test_control_rejects_a_connection_that_is_not_a_database_queue(string $action): void
    {
        foreach (['mysql', 'redis', 'missing'] as $connection) {
            foreach ([['scope' => 'connection'], ['scope' => 'queue', 'queue' => 'emails']] as $target) {
                $this->postJson('/orrery/api/control', ['action' => $action, 'connection' => $connection] + $target)
                    ->assertStatus(422)
                    ->assertJsonFragment(['message' => "The queue connection [{$connection}] does not use the database driver."])
                    ->assertJsonValidationErrors(['connection'])
                    ->assertJsonMissingValidationErrors(['queue']);
            }

            self::assertSame(1, Artisan::call("orrery:{$action}", ['--connection' => $connection]), "orrery:{$action} accepted [{$connection}]");
            self::assertStringContainsString("The queue connection [{$connection}] does not use the database driver.", Artisan::output());
            self::assertSame(1, Artisan::call("orrery:{$action}", ['--connection' => $connection, '--queue' => 'emails']));
        }

        self::assertSame(0, $this->mirror()->table('orrery_signals')->count(), 'a refused target wrote a signal');

        // Control: the database queue connection is accepted, also for a queue without jobs yet.
        $this->postJson('/orrery/api/control', ['action' => $action, 'scope' => 'queue', 'connection' => 'database', 'queue' => 'not-seen-yet'])->assertOk();
        self::assertSame(0, Artisan::call("orrery:{$action}", ['--connection' => 'database', '--queue' => 'not-seen-yet']));
    }

    /**
     * Rejects: a queue target of a connection whose name holds ":", whose
     * stored `connection:queue` value reads back as another connection and
     * queue ("tenant:a" + "emails" and "tenant" + "a:emails" are one value).
     */
    #[DataProvider('actions')]
    public function test_control_rejects_a_queue_of_a_connection_with_a_colon(string $action): void
    {
        $this->app['config']->set('queue.connections.tenant:a', ['driver' => 'database', 'connection' => null, 'table' => 'jobs', 'queue' => 'default']);

        $this->postJson('/orrery/api/control', ['action' => $action, 'scope' => 'queue', 'connection' => 'tenant:a', 'queue' => 'emails'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['connection']);
        self::assertSame(1, Artisan::call("orrery:{$action}", ['--connection' => 'tenant:a', '--queue' => 'emails']));
        self::assertSame(0, $this->mirror()->table('orrery_signals')->count(), 'a refused target wrote a signal');

        // The connection scope stores the name alone: no ambiguity.
        $this->postJson('/orrery/api/control', ['action' => $action, 'scope' => 'connection', 'connection' => 'tenant:a'])->assertOk();
    }

    /**
     * Rejects: a scope value that the 191-character column cannot hold (truncated, or a database error).
     */
    #[DataProvider('actions')]
    public function test_control_rejects_a_scope_value_over_191_characters(string $action): void
    {
        // "database:" is 9 characters: 182 more give 191, 183 more give 192.
        $fits = str_repeat('q', 182);
        $long = str_repeat('q', 183);

        $this->postJson('/orrery/api/control', ['action' => $action, 'scope' => 'queue', 'connection' => 'database', 'queue' => $long])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['queue']);
        self::assertSame(1, Artisan::call("orrery:{$action}", ['--connection' => 'database', '--queue' => $long]));
        self::assertStringContainsString('longer than 191 characters', (string) preg_replace('/\s+/', ' ', Artisan::output()));
        self::assertSame(0, $this->mirror()->table('orrery_signals')->count(), 'a refused target wrote a signal');

        // Multibyte characters count as characters, as the column counts them.
        $this->postJson('/orrery/api/control', ['action' => $action, 'scope' => 'queue', 'connection' => 'database', 'queue' => str_repeat('ą', 182)])->assertOk();
        self::assertSame(0, Artisan::call("orrery:{$action}", ['--connection' => 'database', '--queue' => $fits]));
    }

    /**
     * Rejects: a queue pause of the probe queue name, which would make every queue look paused by Laravel's global pause.
     */
    #[DataProvider('actions')]
    public function test_the_probe_queue_is_reserved(string $action): void
    {
        $probe = NativePause::PROBE_QUEUE;

        $this->postJson('/orrery/api/control', ['action' => $action, 'scope' => 'queue', 'connection' => 'database', 'queue' => $probe])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['queue']);
        self::assertSame(1, Artisan::call("orrery:{$action}", ['--connection' => 'database', '--queue' => $probe]));
        self::assertStringContainsString('is reserved', (string) preg_replace('/\s+/', ' ', Artisan::output()));
        self::assertSame(0, $this->mirror()->table('orrery_signals')->count(), 'a refused target wrote a signal');
        self::assertFalse(Queue::isPaused('database', $probe), 'a refused target paused the probe queue');
    }
}
