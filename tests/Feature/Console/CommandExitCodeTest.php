<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Foundation\Console\Kernel as LaravelKernel;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The exit status of the orrery:* commands when their work fails (todo 1254).
 *
 * The watcher never throws into the host queue, but that rule is for hooks and
 * listeners. An operator command whose requested work did not happen exits
 * non-zero, so a deploy script or a scheduler sees the failure. The
 * scheduled maintenance command is the exception: orrery:tick reports a
 * failed phase in the health events and exits 0, so the scheduler does not
 * treat a degraded phase as a crash (orrery:snapshot is gone).
 *
 * The commands run through Laravel's own console kernel, as `php artisan` of
 * an application runs them. Testbench's kernel rethrows from
 * reportException(), so its exit status is not the status of a real
 * application.
 */
#[FreshSchema]
final class CommandExitCodeTest extends TestCase
{
    /** Commands without an outage row, with the reason: orrery:install builds the schema itself (InstallSchemaReportTest covers its failures); orrery:workers reads only the configuration (WorkersCommandTest). */
    private const NOT_OPERATOR_WORK = ['orrery:install', 'orrery:workers'];

    /**
     * Rejects: a command body that runs inside the watcher (Watcher::run() or attempt()), which reports the error and exits 0 although the work did not happen, and a command of src/CLAUDE.md's operator list that this provider leaves out.
     * Still passes: a command that exits non-zero but leaves a partial result (the test does not check the tables), and a command whose other failures exit 0 (each command's own test covers those).
     *
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('operatorCommands')]
    public function test_an_operator_command_whose_work_fails_exits_non_zero(string $command, array $arguments, int $expected, ?string $seed = null): void
    {
        // A command whose scope is empty has no work to fail: a waiting job or a failed record gives it some.
        if ($seed === 'job') {
            $this->app['queue']->connection('database')->pushRaw(json_encode(['uuid' => '9b1d3c4e-2222-4222-8222-222222222222', 'displayName' => 'Job', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []], JSON_THROW_ON_ERROR));
            self::assertSame(1, DB::table('jobs')->count());
        } elseif ($seed === 'failed') {
            DB::table('failed_jobs')->insert(['uuid' => '9b1d3c4e-3333-4333-8333-333333333333', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'RuntimeException: x', 'failed_at' => now()]);
        }

        $this->mirrorOutage();

        [$status, $output] = $this->runThroughLaravel($command, $arguments);

        self::assertSame($expected, $status, $output);
    }

    /**
     * Rejects: an operator command of the package without a row in operatorCommands() (a new
     * command, or one left out), so its mirror outage could exit 0 unseen.
     * Still passes: a row whose arguments do not reach the command's work (the test above
     * checks the status only).
     */
    public function test_every_operator_command_has_an_outage_row(): void
    {
        $commands = [];

        foreach (glob(dirname(__DIR__, 3).'/src/Console/*Command.php') ?: [] as $file) {
            if (preg_match('/protected \$signature = \'(orrery:[\w-]+)/', (string) file_get_contents($file), $match) === 1) {
                $commands[] = $match[1];
            }
        }

        $covered = [...array_map(static fn (array $row): string => $row[0], self::operatorCommands()), ...array_map(static fn (array $row): string => $row[0], self::maintenanceCommands()), ...self::NOT_OPERATOR_WORK];
        sort($commands);
        $covered = array_values(array_unique($covered));
        sort($covered);

        self::assertGreaterThan(10, count($commands), 'The scan reads the command signatures.');
        self::assertSame($commands, $covered);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: int, 3?: string}>
     */
    public static function operatorCommands(): array
    {
        $uuid = '0d7f2b2a-1111-4111-8111-111111111111';

        return [
            'clear-metrics' => ['orrery:clear-metrics', [], 1],
            'pause' => ['orrery:pause', [], 1],
            'pause a queue' => ['orrery:pause', ['--connection' => 'database', '--queue' => 'default'], 1],
            'continue' => ['orrery:continue', [], 1],
            'terminate' => ['orrery:terminate', [], 1],
            'forget a uuid' => ['orrery:forget', ['id' => $uuid], 1],
            'forget all' => ['orrery:forget', ['--all' => true, '--force' => true], 1, 'failed'],
            'retry a uuid' => ['orrery:retry', ['uuid' => $uuid], 1],
            'retry all' => ['orrery:retry', ['--all' => true, '--force' => true], 1],
            'clear' => ['orrery:clear', ['connection' => 'database', '--force' => true], 1, 'job'],
            'reconcile' => ['orrery:reconcile', [], 1],
            'resolve an id' => ['orrery:resolve', ['id' => $uuid], 1],
            'resolve all' => ['orrery:resolve', ['--all' => true], 1],
            'deployed' => ['orrery:deployed', ['--label' => 'v1'], 1],
        ];
    }

    /**
     * Rejects: a scheduled maintenance command that exits non-zero for a degraded phase, so the scheduler reports a crash every minute during a mirror outage.
     * Still passes: a command that exits 0 and does not report the failure (the health events are checked elsewhere).
     */
    #[DataProvider('maintenanceCommands')]
    public function test_a_scheduled_maintenance_command_exits_zero_when_a_phase_fails(string $command): void
    {
        $this->mirrorOutage();

        [$status, $output] = $this->runThroughLaravel($command, []);

        self::assertSame(0, $status, $output);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function maintenanceCommands(): array
    {
        return [
            'tick' => ['orrery:tick'],
        ];
    }

    /**
     * Runs the command through Laravel's console kernel, which catches an
     * uncaught exception, reports it and returns 1.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{int, string}
     */
    private function runThroughLaravel(string $command, array $arguments): array
    {
        $kernel = new LaravelKernel($this->app, $this->app['events']);
        $output = new BufferedOutput;
        $status = $kernel->handle(new ArrayInput(['command' => $command] + $arguments), $output);

        return [$status, $output->fetch()];
    }
}
