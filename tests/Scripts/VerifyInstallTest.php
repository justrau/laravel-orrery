<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use OrreryVerify\Health;
use OrreryVerify\Options;
use OrreryVerify\Readme;
use OrreryVerify\VerifyFailure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The pure logic of bin/verify-install (bin/lib/verify-install.php): the
 * options, the README commands with their one substitution, and the health
 * warnings. The app flows themselves run only in bin/verify-install.
 */
#[Group('scripts')]
final class VerifyInstallTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2).'/bin/lib/verify-install.php';
    }

    /**
     * Rejects: another default binary, a --php-binary or env override that is
     * ignored, or an unknown argument or app that is accepted.
     * Still passes: a binary path that does not exist (checked at run time).
     */
    public function test_options_take_the_binaries_like_bin_matrix(): void
    {
        $defaults = Options::parse([], []);
        self::assertSame('/opt/homebrew/opt/php@8.3/bin/php', $defaults->php['8.3']);
        self::assertSame('/opt/homebrew/opt/php@8.5/bin/php', $defaults->php['8.5']);
        self::assertSame(['l12-floor', 'l13-latest'], $defaults->apps);

        $options = Options::parse(['--php-binary=8.5=/x/php85', '--app=l12-floor', '--self-test'], ['ORRERY_PHP_8_3' => '/env/php83', 'PW_EXE' => '/env/chrome']);
        self::assertSame('/env/php83', $options->php['8.3']);
        self::assertSame('/x/php85', $options->php['8.5']);
        self::assertSame(['l12-floor'], $options->apps);
        self::assertSame('/env/chrome', $options->chrome);
        self::assertTrue($options->selfTest);

        foreach (['--bogus', '--app=l11'] as $bad) {
            try {
                Options::parse([$bad], []);
                self::fail("{$bad} was accepted");
            } catch (RuntimeException $error) {
                self::assertStringContainsString('unknown', $error->getMessage());
            }
        }
    }

    /**
     * Rejects: a second substitution, a dropped or reordered README command,
     * a repository that is not the path repository with dev-candidate and
     * symlink false, or the original require line kept.
     * Still passes: a README command that the runner cannot execute (it
     * refuses such a command at run time).
     */
    public function test_the_install_steps_are_the_readme_commands_with_one_substitution(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');
        $steps = Readme::installSteps($readme, '/tmp/candidate');

        self::assertSame(['repository', 'command', 'command', 'command'], array_column($steps, 'kind'));
        self::assertSame(['composer require justrau/laravel-orrery:dev-candidate', 'php artisan orrery:install', 'php artisan migrate'], array_column(array_slice($steps, 1), 'text'));
        self::assertSame([true, true, false, false], array_map(static fn (array $s): bool => $s['readme'] !== null, $steps));
        self::assertSame(
            ['type' => 'path', 'url' => '/tmp/candidate', 'options' => ['symlink' => false, 'versions' => ['justrau/laravel-orrery' => 'dev-candidate']]],
            json_decode($steps[0]['text'], true),
        );
    }

    /**
     * Rejects: a README whose require line changed being run unchanged (the
     * substitution would silently not happen).
     * Still passes: a README with a second, unused require line elsewhere.
     */
    public function test_a_readme_without_the_expected_require_line_is_refused(): void
    {
        $readme = str_replace(Readme::REQUIRE_LINE, 'composer require justrau/laravel-orrery:dev-main', (string) file_get_contents(dirname(__DIR__, 2).'/README.md'));

        $this->expectException(VerifyFailure::class);
        $this->expectExceptionMessage('^0.1');
        Readme::installSteps($readme, '/tmp/candidate');
    }

    /**
     * Rejects: a README whose repository command changed (another URL or
     * another repository type) being run with the path repository in its
     * place, so the substitution would hide a broken install step.
     * Still passes: a parser that also accepts the old JSON repository block
     * next to the command.
     */
    public function test_a_readme_without_the_expected_repository_line_is_refused(): void
    {
        $readme = str_replace(Readme::REPOSITORY_LINE, 'composer config repositories.orrery vcs git@github.com:justrau/laravel-orrery.git', (string) file_get_contents(dirname(__DIR__, 2).'/README.md'));

        $this->expectException(VerifyFailure::class);
        $this->expectExceptionMessage('repositories.orrery');
        Readme::installSteps($readme, '/tmp/candidate');
    }

    /**
     * Rejects: removal commands that are not the README's (for example
     * migrate:rollback instead of migrate:reset), or a CSP middleware that is
     * not the README's class.
     * Still passes: a README step that the runner performs without reading it
     * (the file deletions of steps 4 to 6).
     */
    public function test_the_removal_and_csp_come_from_the_readme(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');
        $removal = Readme::removal($readme);

        self::assertSame('php artisan migrate:reset --path=vendor/justrau/laravel-orrery/database/migrations', $removal['reset']);
        self::assertSame('php artisan migrate:reset --path=database/migrations/<date>_add_orrery_jobs_index.php', $removal['index_reset']);
        self::assertSame('composer remove justrau/laravel-orrery', $removal['remove']);

        $csp = Readme::csp($readme);
        self::assertStringStartsWith("<?php\n\nnamespace App\\Http\\Middleware;", $csp['class']);
        self::assertStringContainsString("script-src 'nonce-{nonce}'", $csp['class']);
        self::assertSame("'middleware' => ['web', \\App\\Http\\Middleware\\OrreryContentSecurityPolicy::class],", $csp['config']);
    }

    /**
     * Rejects: a health answer with a degradation window, a missing fence
     * table, a SQLite warning, no tick, a read-only failed-record adapter, an
     * open operation or an outdated schema that counts as clean, and
     * a key that is absent or renamed, which counted as clean (the
     * schema check read a `state` key that /api/health never sends).
     * Still passes: a warning in a field that Health::warnings() does not know.
     */
    public function test_health_warnings(): void
    {
        $clean = [
            'windows' => [], 'tick' => ['observer_tick_at' => 1.5], 'missing_fence_tables' => [], 'fence_unknown_connections' => [],
            'sqlite_warnings' => [], 'uninstrumented_connections' => [], 'missing_indexes' => [], 'sqlite_shared_mirror' => null, 'queue_pause_cache_local' => null,
            'guard_invalid' => false, 'failed_records' => ['adapter' => 'sql'], 'awaiting_resolution' => ['count' => 0],
            'schema' => ['installed' => 10, 'expected' => 10, 'hint' => null],
        ];
        self::assertSame([], Health::warnings($clean));

        $broken = [
            'windows' => [['dropped' => 1]], 'tick' => ['observer_tick_at' => null], 'missing_fence_tables' => ['database'],
            'sqlite_warnings' => [['file' => 'x']], 'failed_records' => ['adapter' => 'read_only'], 'awaiting_resolution' => ['count' => 1],
            'schema' => ['installed' => 9, 'expected' => 10, 'hint' => 'Run php artisan migrate.'],
        ];

        foreach ($broken as $key => $value) {
            self::assertNotSame([], Health::warnings([...$clean, $key => $value]), $key);
        }

        foreach (array_keys($clean) as $key) {
            $without = $clean;
            unset($without[$key]);
            self::assertNotSame([], Health::warnings($without), "an absent {$key} counted as clean");
        }
    }
}
