<?php

namespace Justrau\Orrery\Tests\Feature;

use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\OrreryApplicationServiceProvider;
use Justrau\Orrery\OrreryServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Workbench\App\Models\User;
use Workbench\App\Providers\WorkbenchServiceProvider;
use Workbench\App\Support\WorkbenchAuth;
use Workbench\App\Support\WorkbenchConfiguration;

use function Orchestra\Testbench\default_migration_path;

/**
 * The Workbench's two authorization modes, through real HTTP requests
 * with sessions and CSRF tokens.
 *
 * Like WorkbenchSeederTest, it applies the Workbench configuration to a temporary SQLite file
 * without WorkbenchServiceProvider (which refuses to run outside the launcher), and applies the
 * same WorkbenchAuth methods that the provider calls. WorkbenchServeModesTest proves that the
 * provider reads WORKBENCH_AUTH in the launcher and in the web server child of `serve`.
 */
#[Group('workbench')]
final class WorkbenchStrictAuthTest extends TestCase
{
    private const CONNECTION = 'sqlite';

    private const API = '/orrery/api/control';

    private string $directory;

    private bool $strict = true;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'orrery-workbench-auth-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        touch($this->directory.DIRECTORY_SEPARATOR.'database.sqlite');

        if (str_contains($this->name(), 'default_mode')) {
            $this->strict = false;
        }

        parent::setUp();

        $status = Artisan::call('migrate', ['--force' => true]);
        $this->assertSame(0, $status, Artisan::output());
        WorkbenchAuth::seedUsers();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        self::removeTree($this->directory);
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [OrreryServiceProvider::class, StrictAuthApplicationProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $storage = $this->directory.DIRECTORY_SEPARATOR.'storage';
        mkdir($storage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'sessions', 0700, true);

        $app->detectEnvironment(fn (): string => WorkbenchAuth::environment($this->strict));
        $app['config']->set('app.env', WorkbenchAuth::environment($this->strict));
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('w', 32)));
        WorkbenchConfiguration::apply($app['config'], self::CONNECTION, $this->directory.DIRECTORY_SEPARATOR.'database.sqlite', $storage);
        WorkbenchAuth::apply($app['config'], $this->strict);
        $app->useDatabasePath($this->directory);
        $app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(default_migration_path());
        });
        $app->instance(StrictAuthApplicationProvider::MODE, $this->strict);
    }

    protected function defineRoutes($router): void
    {
        if ($this->strict) {
            /** @var Registrar $router */
            WorkbenchAuth::routes($router);
        }
    }

    /**
     * Rejects: a strict mode that keeps the allow-all gate or the local environment, so a guest
     * reads the dashboard.
     */
    public function test_a_guest_gets_401_on_the_api_and_the_page(): void
    {
        $this->assertSame('production', $this->app->environment());

        $this->getJson('/orrery/api/workers')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
        $this->get('/orrery')->assertStatus(401);
        $this->get('/'.WorkbenchAuth::LOGIN_PATH)->assertOk()->assertSee('operator@example.com')->assertSee('name="_token"', false);
    }

    /**
     * Rejects: a viewer with the operate ability, or a strict mode whose writes are not checked.
     */
    public function test_a_viewer_reads_and_gets_forbidden_action_on_a_write(): void
    {
        $this->login('viewer@example.com');

        $this->getJson('/orrery/api/workers')->assertOk();
        $this->postJson(self::API, ['action' => 'continue'], ['X-CSRF-TOKEN' => $this->token(), 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(403)->assertExactJson(['error' => 'forbidden_action']);
        $this->getJson('/orrery/api/control')->assertOk();
    }

    /**
     * Rejects: an operator without the operate ability.
     */
    public function test_an_operator_reads_and_writes(): void
    {
        $this->login('operator@example.com');

        $this->getJson('/orrery/api/workers')->assertOk();
        $this->postJson(self::API, ['action' => 'continue'], ['X-CSRF-TOKEN' => $this->token(), 'X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    }

    /**
     * Rejects: a gate that lets every signed-in user view.
     */
    public function test_a_denied_user_gets_403_forbidden(): void
    {
        $this->login('denied@example.com');

        $this->getJson('/orrery/api/workers')->assertStatus(403)->assertExactJson(['error' => 'forbidden']);
        $this->get('/orrery')->assertStatus(403);
    }

    /**
     * Rejects: a strict mode without CSRF checks (the login and the dashboard writes).
     */
    public function test_the_login_and_the_writes_need_the_csrf_token(): void
    {
        $this->post('/'.WorkbenchAuth::LOGIN_PATH, ['email' => 'operator@example.com', 'password' => WorkbenchAuth::PASSWORD])->assertStatus(419);
        $this->assertGuest('web');

        $this->login('operator@example.com');
        $this->postJson(self::API, ['action' => 'continue'], ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(419);
    }

    /**
     * Rejects: a login that accepts any password.
     */
    public function test_a_wrong_password_signs_nobody_in(): void
    {
        $this->get('/'.WorkbenchAuth::LOGIN_PATH);

        $this->post('/'.WorkbenchAuth::LOGIN_PATH, ['_token' => $this->token(), 'email' => 'operator@example.com', 'password' => 'wrong'])
            ->assertRedirect('/'.WorkbenchAuth::LOGIN_PATH);
        $this->assertGuest('web');
        $this->getJson('/orrery/api/workers')->assertStatus(401);
    }

    /**
     * Rejects: a logout that keeps the user, so the SPA never reaches the session-expired state.
     */
    public function test_a_logout_gives_401_on_the_next_read(): void
    {
        $this->login('viewer@example.com');
        $this->getJson('/orrery/api/workers')->assertOk();

        $this->post('/'.WorkbenchAuth::LOGOUT_PATH, ['_token' => $this->token()])->assertRedirect('/'.WorkbenchAuth::LOGIN_PATH);

        $this->getJson('/orrery/api/workers')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
    }

    /**
     * Rejects: a strict mode whose sessions write the application's database file.
     */
    public function test_strict_sessions_use_files_in_the_owned_storage(): void
    {
        $this->assertSame('file', config('session.driver'));
        $this->assertSame($this->directory.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, ['storage', 'framework', 'sessions']), config('session.files'));
        $this->assertFalse((bool) config('app.debug'));
    }

    /**
     * Rejects: a change of the default mode, which stays allow-all for every visitor.
     */
    public function test_the_default_mode_allows_every_visitor(): void
    {
        $this->assertSame(WorkbenchServiceProvider::ENVIRONMENT, $this->app->environment());
        $this->assertSame('database', config('session.driver'));

        $this->getJson('/orrery/api/workers')->assertOk();
        $this->postJson(self::API, ['action' => 'continue'], ['X-CSRF-TOKEN' => $this->token(), 'X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->get('/'.WorkbenchAuth::LOGIN_PATH)->assertNotFound();
    }

    /**
     * @return array<string, array{mixed, bool|null}>
     */
    public static function values(): array
    {
        return [
            'unset' => [null, false],
            'empty' => ['', false],
            'strict' => ['strict', true],
            'capitalised' => ['Strict', null],
            'another word' => ['yes', null],
            'padded' => [' strict', null],
        ];
    }

    /**
     * Rejects: a mistyped mode that falls back to the allow-all mode.
     */
    #[DataProvider('values')]
    public function test_the_mode_accepts_only_unset_empty_or_strict(mixed $value, ?bool $strict): void
    {
        if ($strict === null) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The Workbench refuses WORKBENCH_AUTH=');
        }

        $this->assertSame($strict, WorkbenchAuth::parse($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function specialValues(): array
    {
        return [
            'false' => ['false'],
            '(false)' => ['(false)'],
            'empty' => ['empty'],
            '(empty)' => ['(empty)'],
            'null' => ['null'],
        ];
    }

    /**
     * Rejects: a mode read through Env::get(), which turns `false` and `empty` into false and ''
     * (the allow-all mode).
     */
    #[DataProvider('specialValues')]
    public function test_the_process_environment_refuses_the_special_words_of_env(string $value): void
    {
        putenv(WorkbenchAuth::VARIABLE.'='.$value);
        $_SERVER[WorkbenchAuth::VARIABLE] = $value;
        $_ENV[WorkbenchAuth::VARIABLE] = $value;

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The Workbench refuses WORKBENCH_AUTH=['.$value.']');

            WorkbenchAuth::strict();
        } finally {
            putenv(WorkbenchAuth::VARIABLE);
            unset($_SERVER[WorkbenchAuth::VARIABLE], $_ENV[WorkbenchAuth::VARIABLE]);
        }
    }

    /**
     * Rejects: a WAL switch that refuses its own name.
     *
     * @return array<string, array{string|null, string|null}>
     */
    public static function journals(): array
    {
        return [
            'unset' => [null, 'wal'],
            'empty' => ['', 'wal'],
            'wal' => ['wal', 'wal'],
            'delete' => ['delete', 'delete'],
            'rollback' => ['rollback', null],
            'WAL' => ['WAL', null],
        ];
    }

    /**
     * Rejects: a journal switch that refuses `wal`, or accepts another word.
     */
    #[DataProvider('journals')]
    public function test_the_journal_switch_accepts_unset_empty_wal_or_delete(?string $value, ?string $mode): void
    {
        if ($mode === null) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The Workbench refuses ORRERY_TEST_SQLITE_JOURNAL=');
        }

        $this->assertSame($mode, WorkbenchConfiguration::journalMode($value));
    }

    /**
     * Rejects: abilities given to the wrong user.
     */
    public function test_each_user_has_exactly_its_abilities(): void
    {
        $abilities = [];

        foreach (User::query()->orderBy('email')->get() as $user) {
            $abilities[$user->email] = array_values(array_filter(
                [WorkbenchAuth::VIEW, WorkbenchAuth::OPERATE],
                static fn (string $ability): bool => Gate::forUser($user)->allows($ability),
            ));
        }

        $this->assertSame([
            'denied@example.com' => [],
            'operator@example.com' => [WorkbenchAuth::VIEW, WorkbenchAuth::OPERATE],
            'viewer@example.com' => [WorkbenchAuth::VIEW],
        ], $abilities);
        $this->assertFalse(Gate::allows(WorkbenchAuth::VIEW), 'A guest may view.');
    }

    private function login(string $email): void
    {
        $this->get('/'.WorkbenchAuth::LOGIN_PATH)->assertOk();
        $this->post('/'.WorkbenchAuth::LOGIN_PATH, ['_token' => $this->token(), 'email' => $email, 'password' => WorkbenchAuth::PASSWORD])
            ->assertRedirect('/orrery');
        $this->assertAuthenticated('web');
        $this->assertSame($email, Orrery::actor(request()));
    }

    private function token(): string
    {
        return (string) $this->app['session']->driver()->token();
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            self::removeTree($path.DIRECTORY_SEPARATOR.$name);
        }

        rmdir($path);
    }
}

/**
 * The gates of WorkbenchServiceProvider without its path guards: the same parent and the same
 * WorkbenchAuth::defineGates() call.
 */
final class StrictAuthApplicationProvider extends OrreryApplicationServiceProvider
{
    public const MODE = 'workbench.test.strict';

    protected function gate(): void
    {
        WorkbenchAuth::defineGates((bool) $this->app->make(self::MODE));
    }
}
