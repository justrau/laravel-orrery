<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;
use RuntimeException;

/**
 * The actor of a control write: orrery_signals.issued_by, from
 * Orrery::actorUsing() or the default resolver in the HTTP caller, and `cli`
 * from the commands.
 */
final class ControlActorTest extends ControllerTest
{
    /**
     * Known limit: it would pass a resolver that prefers the name when the fixture user has no name.
     */
    public function test_the_default_actor_is_the_users_email_and_the_control_list_returns_it(): void
    {
        $user = (new User)->forceFill(['id' => 7, 'name' => 'Alice', 'email' => 'alice@example.test']);

        $this->actingAs($user)->postJson('/orrery/api/control', ['action' => 'pause', 'scope' => 'queue', 'connection' => 'database', 'queue' => 'mail'])->assertOk();

        self::assertSame(['alice@example.test'], $this->issuedBy('pause'));
        $this->actingAs($user)->getJson('/orrery/api/control')->assertOk()->assertJsonPath('0.issued_by', 'alice@example.test');
    }

    /**
     * Rejects: a control write that reads the parsed body, which the host's TrimStrings changes: a pause of the
     * queue `high ` paused `high`. The body is read raw, so the target keeps its edge whitespace.
     */
    public function test_the_control_body_is_read_past_trim_strings(): void
    {
        $this->actingAs(new User)->postJson('/orrery/api/control', ['action' => 'pause', 'scope' => 'queue', 'connection' => 'database', 'queue' => 'high '])
            ->assertOk()
            ->assertJsonPath('value', 'database:high ');

        self::assertSame(['database:high '], $this->mirror()->table('orrery_signals')->where('signal', 'pause')->pluck('scope_value')->all());
    }

    /**
     * Rejects: a write without a scope that names a connection or a queue and is taken as a global one.
     */
    public function test_a_target_without_a_scope_is_refused(): void
    {
        $this->actingAs(new User)->postJson('/orrery/api/control', ['action' => 'pause', 'connection' => 'database'])->assertStatus(422);
        $this->actingAs(new User)->postJson('/orrery/api/control', ['action' => 'terminate', 'queue' => 'mail'])->assertStatus(422);

        self::assertSame(0, $this->mirror()->table('orrery_signals')->count(), 'Nothing was paused or terminated.');
    }

    /**
     * Rejects: a default actor read from the default guard while
     * `orrery.guard` names another one: the admin's pause would store null,
     * or the identity of the same session on the default guard. The admin
     * signs in through setUser(), not actingAs(), because actingAs() makes
     * its guard the default one.
     *
     * Known limit: it would pass a resolver that asks every guard and prefers the configured one.
     */
    public function test_the_default_actor_reads_the_configured_guard(): void
    {
        $this->app['config']->set('auth.guards.orrery-admin', ['driver' => 'session', 'provider' => 'users']);
        $this->app['config']->set('orrery.guard', 'orrery-admin');
        $this->app['auth']->guard('orrery-admin')->setUser((new User)->forceFill(['id' => 8, 'email' => 'admin@example.test']));
        $this->app['auth']->guard('web')->setUser((new User)->forceFill(['id' => 9, 'email' => 'web@example.test']));

        $this->postJson('/orrery/api/control', ['action' => 'pause', 'scope' => 'queue', 'connection' => 'database', 'queue' => 'mail'])->assertOk();
        self::assertSame(['admin@example.test'], $this->issuedBy('pause'));

        $this->app['auth']->guard('orrery-admin')->forgetUser();
        $this->postJson('/orrery/api/control', ['action' => 'terminate'])->assertOk();
        self::assertSame([null], $this->issuedBy('terminate'), 'no admin: no actor, never the default guard\'s user');
    }

    /**
     * Known limit: it would pass a resolver that skips the email only when it is null (an empty email is not tested).
     */
    public function test_the_default_actor_falls_back_to_the_name_then_the_key_then_null(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 7, 'name' => 'Alice']))->postJson('/orrery/api/control', ['action' => 'pause'])->assertOk();
        self::assertSame(['Alice'], $this->issuedBy('pause'));

        $this->actingAs((new User)->forceFill(['id' => 7]))->postJson('/orrery/api/control', ['action' => 'terminate'])->assertOk();
        self::assertSame(['7'], $this->issuedBy('terminate'));

        $this->app['auth']->forgetGuards();
        $this->postJson('/orrery/api/control', ['action' => 'pause', 'scope' => 'connection', 'connection' => 'database'])->assertOk();
        self::assertSame([null], $this->issuedBy('pause', 'connection'), 'no user: no actor');
    }

    /**
     * Known limit: it would pass a cap that counts characters after removing only NUL.
     */
    public function test_a_resolved_actor_is_cleaned_and_capped_at_191_characters(): void
    {
        Orrery::actorUsing(static fn (?Request $request): string => "ops\x00\x1B[31m\xC3\x28".str_repeat('界', 300));

        $this->postJson('/orrery/api/control', ['action' => 'pause'])->assertOk();

        $actor = (string) $this->issuedBy('pause')[0];
        self::assertSame(191, mb_strlen($actor, 'UTF-8'));
        self::assertTrue(mb_check_encoding($actor, 'UTF-8'));
        self::assertStringStartsWith('ops [31m', $actor, 'NUL and ESC become one space');
    }

    /**
     * Known limit: it would pass a controller that catches the error but also skips the report when reports are throttled.
     */
    public function test_a_throwing_resolver_is_reported_and_the_signal_is_written_without_an_actor(): void
    {
        Exceptions::fake();
        Orrery::actorUsing(static function (): never {
            throw new RuntimeException('The actor resolver failed.');
        });

        $this->postJson('/orrery/api/control', ['action' => 'pause', 'scope' => 'queue', 'connection' => 'database', 'queue' => 'mail'])->assertOk();

        self::assertSame([null], $this->issuedBy('pause'));
        Exceptions::assertReported(static fn (WatchedOperationFailed $error): bool => $error->operation === 'control:actor' && $error->causeClass === RuntimeException::class && str_contains($error->getMessage(), 'The actor resolver failed.'));
    }

    /**
     * Known limit: it would pass a resolver that serializes only models without hidden attributes (no hidden attribute is tested).
     */
    public function test_a_resolver_that_returns_a_model_stores_no_actor(): void
    {
        Orrery::actorUsing(static fn (?Request $request): mixed => (new User)->forceFill(['id' => 7, 'email' => 'alice@example.test']));

        $this->postJson('/orrery/api/control', ['action' => 'pause'])->assertOk();

        self::assertSame([null], $this->issuedBy('pause'), 'never the model\'s JSON');
    }

    /**
     * Known limit: it would pass a controller that catches only the resolver's own call if the cleaning never casts (it does not).
     */
    public function test_a_resolver_that_returns_a_throwing_stringable_still_writes_the_signal(): void
    {
        Exceptions::fake();
        Orrery::actorUsing(static fn (?Request $request): mixed => new class extends User
        {
            public function __toString(): string
            {
                throw new RuntimeException('The model cannot be encoded.');
            }
        });

        $this->postJson('/orrery/api/control', ['action' => 'terminate'])->assertOk();

        self::assertSame([null], $this->issuedBy('terminate'));
    }

    /**
     * Known limit: it would pass a storage boundary that cleans only global pauses if the queue annotation were not tested (it is). A continue writes no row.
     */
    public function test_the_signal_writes_clean_the_actor_at_the_storage_boundary(): void
    {
        $signals = $this->app->make(SignalState::class);
        $signals->pause('global', '', "ops\x00".str_repeat('a', 300));
        $signals->pause('queue', 'database:mail', "x\ny");
        $signals->terminate('global', '', issuedBy: "\x00");

        self::assertSame(['ops '.str_repeat('a', 187)], $this->issuedBy('pause', 'global'));
        self::assertSame(['x y'], $this->issuedBy('pause', 'queue'));
        self::assertSame([null], $this->issuedBy('terminate'));
    }

    /**
     * Known limit: it would pass a command that stores `cli` only for a global scope if every scope were global here (the second pause is a queue scope). A continue writes no row.
     */
    public function test_the_commands_store_cli(): void
    {
        $this->artisan('orrery:pause')->assertExitCode(0);
        $this->artisan('orrery:pause', ['--connection' => 'database', '--queue' => 'mail'])->assertExitCode(0);
        $this->artisan('orrery:continue', ['--connection' => 'database', '--queue' => 'other'])->assertExitCode(0);
        $this->artisan('orrery:terminate', ['--connection' => 'database'])->assertExitCode(0);

        self::assertSame(['cli', 'cli'], $this->issuedBy('pause'));
        self::assertSame([], $this->issuedBy('continue'));
        self::assertSame(['cli'], $this->issuedBy('terminate'));
    }

    /**
     * Known limit: it would pass a worker read that resolves the actor only when a request is bound.
     */
    public function test_a_worker_signal_read_never_resolves_the_actor(): void
    {
        $calls = 0;
        Orrery::actorUsing(static function () use (&$calls): string {
            $calls++;

            return 'someone';
        });

        $signals = $this->app->make(SignalState::class);
        $signals->pause('global', '');
        SignalState::flushCache();

        self::assertTrue($signals->connectionPaused('database'));
        self::assertSame(0, $calls);
        self::assertSame([null], $this->issuedBy('pause'));
    }

    /**
     * @return list<string|null>
     */
    private function issuedBy(string $signal, ?string $scope = null): array
    {
        return $this->mirror()->table('orrery_signals')->where('signal', $signal)
            ->when($scope !== null, static fn ($query) => $query->where('scope', $scope))
            ->orderBy('id')->pluck('issued_by')
            ->map(static fn (mixed $value): ?string => $value === null ? null : (string) $value)->all();
    }
}
