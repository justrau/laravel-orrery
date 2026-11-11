<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Workers;

use InvalidArgumentException;
use Justrau\Orrery\Workers\Pool;
use Justrau\Orrery\Workers\ProcessConfig;
use PHPUnit\Framework\TestCase;

/**
 * The exact output of each format. The second pool has a queue with a
 * quote and spaces, a path with a space and a log directory with `%`, so
 * every quoting rule shows.
 */
final class ProcessConfigTest extends TestCase
{
    /**
     * Rejects: a list without --name, a reordered --queue (priority order matters), an unquoted word with a space
     * or a quote, and a default option that the pool did not set.
     */
    public function test_the_list_format(): void
    {
        $expected = <<<'TEXT'
            default (2 processes): php artisan queue:work database --queue=high,default --name=default --tries=3 --timeout=90
            reports (1 process): php artisan queue:work database '--queue=it'\''s big,low prio' --name=reports --timeout=0 --sleep=0.5 --backoff=1,5,10 --memory=256 --max-jobs=500 --max-time=3600 --rest=1 --force

            TEXT;

        self::assertSame($expected, self::config()->list(self::pools()));
    }

    /**
     * Rejects: a stopwaitsecs that does not exceed the job timeout, a `%` that supervisord would expand, a command
     * whose words supervisord's shlex would split at a space, a missing stopasgroup or killasgroup, and the start
     * defaults (3 retries of a worker that exits within a second) that leave a program FATAL after a short
     * database outage.
     */
    public function test_the_supervisor_format(): void
    {
        $expected = <<<'TEXT'
            ; Orrery worker pools for supervisord (php artisan orrery:workers --format=supervisor).
            ; Orrery never starts workers. Save this as /etc/supervisor/conf.d/shop-workers.conf,
            ; then run: supervisorctl reread && supervisorctl update

            [program:shop-default]
            command=/usr/bin/php '/srv/my app/artisan' queue:work database --queue=high,default --name=default --tries=3 --timeout=90
            process_name=%(program_name)s_%(process_num)02d
            numprocs=2
            autostart=true
            autorestart=true
            startsecs=1
            startretries=60
            stopasgroup=true
            killasgroup=true
            user=deploy
            redirect_stderr=true
            stdout_logfile=/var/log/shop 100%%/shop-default.log
            stopwaitsecs=120

            [program:shop-reports]
            command=/usr/bin/php '/srv/my app/artisan' queue:work database '--queue=it'\''s big,low prio' --name=reports --timeout=0 --sleep=0.5 --backoff=1,5,10 --memory=256 --max-jobs=500 --max-time=3600 --rest=1 --force
            process_name=%(program_name)s_%(process_num)02d
            numprocs=1
            autostart=true
            autorestart=true
            startsecs=1
            startretries=60
            stopasgroup=true
            killasgroup=true
            user=deploy
            redirect_stderr=true
            stdout_logfile=/var/log/shop 100%%/shop-reports.log
            ; --timeout=0 (no job timeout): raise stopwaitsecs above your longest job.
            stopwaitsecs=3600

            TEXT;

        self::assertSame($expected, self::config()->supervisor(self::pools()));
    }

    /**
     * Rejects: a TimeoutStopSec that does not exceed the job timeout, an ExecStart word that systemd would split at a
     * space, an unescaped `%` or `$`, a missing enable line with the process count, and systemd's start limit (5
     * starts in 10 s), which leaves the workers down after a database restart of a few seconds.
     */
    public function test_the_systemd_format(): void
    {
        $expected = <<<'TEXT'
            # Orrery worker pools for systemd (php artisan orrery:workers --format=systemd).
            # Orrery never starts workers. Save each unit under /etc/systemd/system/,
            # then run systemctl daemon-reload and the unit's enable line.

            # /etc/systemd/system/shop-default@.service
            # systemctl enable --now shop-default@{1..2}
            [Unit]
            Description=shop queue worker default %i (Orrery pool)
            After=network.target
            StartLimitIntervalSec=0

            [Service]
            User=deploy
            WorkingDirectory=/srv/my app
            ExecStart=/usr/bin/php "/srv/my app/artisan" queue:work database --queue=high,default --name=default --tries=3 --timeout=90
            Restart=always
            RestartSec=1
            KillSignal=SIGTERM
            TimeoutStopSec=120

            [Install]
            WantedBy=multi-user.target

            # /etc/systemd/system/shop-reports@.service
            # systemctl enable --now shop-reports@{1..1}
            [Unit]
            Description=shop queue worker reports %i (Orrery pool)
            After=network.target
            StartLimitIntervalSec=0

            [Service]
            User=deploy
            WorkingDirectory=/srv/my app
            ExecStart=/usr/bin/php "/srv/my app/artisan" queue:work database "--queue=it's big,low prio" --name=reports --timeout=0 --sleep=0.5 --backoff=1,5,10 --memory=256 --max-jobs=500 --max-time=3600 --rest=1 --force
            Restart=always
            RestartSec=1
            KillSignal=SIGTERM
            TimeoutStopSec=infinity

            [Install]
            WantedBy=multi-user.target

            TEXT;

        self::assertSame($expected, self::config()->systemd(self::pools()));
    }

    /**
     * Rejects: a systemd word whose `"`, `\`, `%` or `$` reaches systemd unescaped.
     */
    public function test_systemd_escapes_quotes_backslashes_specifiers_and_variables(): void
    {
        $pool = new Pool('odd', 'database', ['a"b\\c', '50%$HOME'], 1, []);
        $out = (new ProcessConfig('/usr/bin/php', '/app', 'u', 'p', '/logs'))->systemd([$pool]);

        self::assertStringContainsString('ExecStart=/usr/bin/php /app/artisan queue:work database "--queue=a\\"b\\\\c,50%%$$HOME" --name=odd'."\n", $out);
    }

    /**
     * Rejects: a shell word that a POSIX shell would read differently (each case is run through sh).
     */
    public function test_shell_words_survive_a_real_shell(): void
    {
        foreach (["it's", 'a b', '$HOME', '`x`', 'a"b', 'back\\slash', '*', '', 'plain-word_1.2:3,4/5=6@7%8+9'] as $word) {
            $echoed = shell_exec('printf %s '.ProcessConfig::shellWord($word));
            self::assertSame($word, $echoed ?? '', $word);
        }
    }

    /**
     * Rejects: a value that supervisord's parser would cut at " ;" or " #", and a line break in any format.
     */
    public function test_values_that_a_format_cannot_carry_are_refused(): void
    {
        $refused = 0;
        $cut = new Pool('cut', 'database', ['a #b'], 1, []);

        try {
            self::config()->supervisor([$cut]);
        } catch (InvalidArgumentException $error) {
            $refused++;
            self::assertStringContainsString('Pool cut', $error->getMessage());
        }

        foreach (['list', 'supervisor', 'systemd', 'forge'] as $format) {
            try {
                (new ProcessConfig('php', "/app\nrm", 'u', 'p', '/logs'))->{$format}([new Pool('x', 'database', ["a\nb"], 1, [])]);
            } catch (InvalidArgumentException) {
                $refused++;
            }
        }

        self::assertSame(5, $refused);
        // The list format reads no supervisord value: " #" is a plain quoted shell word there.
        self::assertStringContainsString("'--queue=a #b'", self::config()->list([$cut]));
    }

    /**
     * Rejects: a Forge command whose `%` supervisord expands (Forge writes it into a supervisord program), or whose
     * " #" or " ;" cuts it.
     */
    public function test_the_forge_command_is_a_supervisord_value(): void
    {
        $forms = self::config()->forge([new Pool('odd', 'database', ['50%'], 1, [])]);
        self::assertSame(['Command', 'php artisan queue:work database --queue=50%% --name=odd'], $forms[0]['custom'][1]);

        $this->expectException(InvalidArgumentException::class);
        self::config()->forge([new Pool('cut', 'database', ['a #b'], 1, [])]);
    }

    /**
     * Rejects: a relative application path in supervisord or systemd (both start the program outside it), a relative
     * log directory in supervisord, and a PHP binary that systemd would not find (ExecStart needs a path).
     */
    public function test_paths_must_be_absolute_where_the_process_manager_needs_it(): void
    {
        $pool = [new Pool('default', 'database', ['default'], 1, [])];
        $cases = [
            'supervisor path' => static fn () => (new ProcessConfig('/usr/bin/php', 'app', 'u', 'p', '/logs'))->supervisor($pool),
            'supervisor log dir' => static fn () => (new ProcessConfig('/usr/bin/php', '/app', 'u', 'p', 'logs'))->supervisor($pool),
            'systemd path' => static fn () => (new ProcessConfig('/usr/bin/php', 'app', 'u', 'p', '/logs'))->systemd($pool),
            'systemd php' => static fn () => (new ProcessConfig('php', '/app', 'u', 'p', '/logs'))->systemd($pool),
            'forge path' => static fn () => (new ProcessConfig('php', 'app', 'u', 'p', '/logs'))->forge($pool),
        ];

        foreach ($cases as $case => $render) {
            try {
                $render();
                self::fail("{$case} was accepted");
            } catch (InvalidArgumentException $refused) {
                self::assertStringContainsString('absolute', $refused->getMessage(), $case);
            }
        }

        // supervisord finds a bare `php` on its PATH, and the list format runs from the application directory.
        self::assertStringContainsString('command=php /app/artisan', (new ProcessConfig('php', '/app', 'u', 'p', '/logs'))->supervisor($pool));
        self::assertStringContainsString('php artisan queue:work', (new ProcessConfig('php', 'app', 'u', 'p', 'logs'))->list($pool));
    }

    /**
     * Rejects: PHP_BINARY under PHP-FPM (an FPM binary cannot run artisan), and `php` under the CLI.
     */
    public function test_the_default_php_binary(): void
    {
        self::assertSame('/usr/bin/php8.4', ProcessConfig::defaultPhp('cli', '/usr/bin/php8.4'));
        self::assertSame('php', ProcessConfig::defaultPhp('fpm-fcgi', '/usr/sbin/php-fpm8.4'));
        self::assertSame('php', ProcessConfig::defaultPhp('phpdbg', '/usr/bin/phpdbg'));
        self::assertSame('php', ProcessConfig::defaultPhp('cli', ''));
    }

    /**
     * Rejects: a Forge Custom command without --name, a stop wait not above the timeout, and Queue worker values
     * that invent a Forge default.
     */
    public function test_the_forge_fields(): void
    {
        $forms = self::config()->forge(self::pools());

        self::assertSame([
            ['Name', 'shop-default'],
            ['Command', 'php artisan queue:work database --queue=high,default --name=default --tries=3 --timeout=90'],
            ['Working Directory', '/srv/my app'],
            ['User', 'deploy'],
            ['Processes', '2'],
            ['Stop Seconds', '120'],
            ['Stop Signal', 'SIGTERM'],
        ], $forms[0]['custom']);
        self::assertSame([
            ['Queue Connection', 'database'],
            ['Number of Processes', '2'],
            ['Queue', 'high,default'],
            ['Backoff', "not set (Laravel's default: 0)"],
            ['Sleep', "not set (Laravel's default: 3)"],
            ['Rest', "not set (Laravel's default: 0)"],
            ['Timeout', '90'],
            ['Tries', '3'],
            ['Memory', "not set (Laravel's default: 128)"],
            ['Force', 'no'],
        ], $forms[0]['queue_worker']);
        self::assertSame(['--max-jobs', 'no field: use the Custom tab'], $forms[1]['queue_worker'][10]);
        self::assertSame(['Stop Seconds', '3600'], $forms[1]['custom'][5]);
    }

    private static function config(): ProcessConfig
    {
        return new ProcessConfig('/usr/bin/php', '/srv/my app', 'deploy', 'shop', '/var/log/shop 100%');
    }

    /**
     * @return list<Pool>
     */
    private static function pools(): array
    {
        return [
            new Pool('default', 'database', ['high', 'default'], 2, ['tries' => 3, 'timeout' => 90]),
            new Pool('reports', 'database', ["it's big", 'low prio'], 1, [
                'timeout' => 0, 'sleep' => 0.5, 'backoff' => [1, 5, 10], 'memory' => 256,
                'max_jobs' => 500, 'max_time' => 3600, 'rest' => 1, 'force' => true,
            ]),
        ];
    }
}
