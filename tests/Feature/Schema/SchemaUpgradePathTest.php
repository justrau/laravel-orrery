<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Schema;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\UsesPrivateSkeleton;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;

/**
 * The schema upgrade path of existing server installs before v0.1:
 * the
 * `vendor:publish --tag=orrery-migrations --force` republish, the
 * open-operation guard of PackageSchema::drop(), and the documented
 * `migrate:refresh --path=<the package migration> --realpath`. The
 * republish writes into a private skeleton copy (Support\UsesPrivateSkeleton).
 */
#[FreshSchema]
final class SchemaUpgradePathTest extends TestCase
{
    use UsesPrivateSkeleton;

    protected function setUp(): void
    {
        $this->startPrivateSkeleton();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->endPrivateSkeleton();
        }
    }

    /**
     * Rejects: a pre-v0.1 path whose refresh rebuilds an old
     * published copy of the package migration (a copy published before the schema marker holds
     * the old inline schema, and a publish without --force keeps it), steps that leave out
     * the --force republish, and steps that write the fence migrations without running them.
     */
    public function test_the_force_republish_replaces_an_old_copy_before_the_refresh(): void
    {
        $name = '2026_09_23_000000_create_orrery_tables.php';
        $package = realpath(__DIR__.'/../../../database/migrations/'.$name);
        self::assertIsString($package);
        $published = database_path('migrations/'.$name);
        self::assertStringStartsWith(base_path().DIRECTORY_SEPARATOR, $published, 'The republish writes into the private skeleton.');

        if (! is_dir(dirname($published))) {
            mkdir(dirname($published), 0755, true);
        }

        $old = "<?php\n\n// The inline schema of an early build, published before the schema marker.\nreturn new class extends Illuminate\\Database\\Migrations\\Migration {};\n";
        file_put_contents($published, $old);

        self::assertSame(0, Artisan::call('vendor:publish', ['--tag' => 'orrery-migrations']), Artisan::output());
        self::assertStringEqualsFile($published, $old, 'A publish without --force keeps the old copy.');

        self::assertStringStartsWith('First run php artisan vendor:publish --tag=orrery-migrations --force ', PackageSchema::UPGRADE_STEPS);
        // An interrupted first `migrate` (MySQL) leaves tables without a marker and without a migrations row.
        self::assertStringContainsString('if it reports Nothing to rollback, drop the remaining orrery_* tables, then run php artisan migrate', PackageSchema::UPGRADE_STEPS);
        self::assertStringContainsString('then php artisan orrery:install --fence, then php artisan migrate; ', PackageSchema::UPGRADE_STEPS);
        self::assertSame(0, Artisan::call('vendor:publish', ['--tag' => 'orrery-migrations', '--force' => true]), Artisan::output());
        self::assertFileEquals($package, $published, 'The --force republish replaces the old copy with the package migration.');

        $this->mirror()->table('orrery_settings')->insert(['key' => 'p5b_old_schema', 'value' => '1']);
        self::assertSame(0, Artisan::call('migrate:refresh', ['--database' => $this->testEnvironment()->mirrorConnectionName(), '--path' => $published, '--realpath' => true]), Artisan::output());
        self::assertSame(PackageSchema::VERSION, PackageSchema::installedVersion($this->mirror()));
        $sibling = $this->testEnvironment()->siblingDatabase() !== null;
        self::assertSame($sibling ? 1 : 0, $this->mirror()->table('orrery_settings')->where('key', 'p5b_old_schema')->count(), 'A server mirror is rebuilt empty; the sibling keeps its file (D12).');
    }

    /**
     * Rejects: a drop() that loses an open operation of the ledger (a claimed retry whose
     * publication only the ledger can resolve), a guard that also refuses for finished
     * (`done`, `aborted`) operations, a refusal without the upgrade hint, a guard that is off by
     * default, and a guard that is on in the test environment (the fresh-mode teardown with open operations would fail).
     */
    public function test_drop_refuses_while_an_operation_is_open(): void
    {
        self::assertTrue((new \ReflectionProperty(PackageSchema::class, 'openOperationGuard'))->getDefaultValue(), 'The guard is on outside the test environment.');
        $schema = $this->mirrorSchema();

        // The test environment turns the guard off: the teardown's rollback drops open operations.
        $this->operation('d', 'published');
        PackageSchema::drop($schema);
        self::assertFalse($schema->hasTable('orrery_operations'), 'The guard is off in the test environment.');
        $this->restore($schema);

        $this->operation('a', 'done');
        $this->operation('b', 'aborted');
        $this->operation('c', 'claimed');

        PackageSchema::useOpenOperationGuardForTesting(true);

        try {
            try {
                PackageSchema::drop($schema);
                self::fail('drop() dropped the tables of an open operation.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('orrery:resolve --all', $error->getMessage());
                self::assertStringContainsString('migrate:refresh --path=', $error->getMessage());
            }

            self::assertTrue($schema->hasTable('orrery_operations'), 'The refused drop changed nothing.');
            self::assertTrue($schema->hasTable('orrery_jobs'));

            // Every operation finished: the drop runs.
            $this->mirror()->table('orrery_operations')->where('id', str_pad('c', 32, '0'))->update(['state' => 'done']);
            PackageSchema::drop($schema);
            self::assertFalse($schema->hasTable('orrery_operations'));
        } finally {
            PackageSchema::useOpenOperationGuardForTesting(false);
            $this->restore($schema);
        }
    }

    /**
     * Rejects (N-5): an upgrade command that also rolls back an application migration (a plain
     * `migrate:rollback`, `--step` or `migrate:reset`), and a refresh that leaves the package
     * tables of the old schema in place.
     * Still passes: a refresh that rolls back every migration of the package path (there is one).
     */
    public function test_migrate_refresh_with_the_package_path_touches_only_the_package_migration(): void
    {
        $directory = sys_get_temp_dir().'/orrery-d8-2-'.bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory.'/2026_11_09_000000_create_app_widgets.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('app_widgets', static function (Blueprint $table): void {
                        $table->id();
                    });
                }

                public function down(): void
                {
                    Schema::dropIfExists('app_widgets');
                }
            };
            PHP);
        $primary = $this->app['db']->connection(TestEnvironment::PRIMARY);
        $package = realpath(__DIR__.'/../../../database/migrations/2026_09_23_000000_create_orrery_tables.php');
        self::assertIsString($package);
        $sibling = $this->testEnvironment()->siblingDatabase() !== null;

        try {
            self::assertSame(0, Artisan::call('migrate', ['--database' => TestEnvironment::PRIMARY, '--path' => $directory, '--realpath' => true]), Artisan::output());
            $primary->table('app_widgets')->insert(['id' => 7]);
            $this->mirror()->table('orrery_settings')->insert(['key' => 'd8_2_old_schema', 'value' => '1']);

            self::assertSame(0, Artisan::call('migrate:refresh', ['--database' => $this->testEnvironment()->mirrorConnectionName(), '--path' => $package, '--realpath' => true]), Artisan::output());

            self::assertTrue($primary->getSchemaBuilder()->hasTable('app_widgets'), 'The application migration was not rolled back.');
            self::assertSame([7], $primary->table('app_widgets')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
            self::assertSame(1, $primary->table('migrations')->where('migration', '2026_11_09_000000_create_app_widgets')->count());
            self::assertSame(PackageSchema::VERSION, PackageSchema::installedVersion($this->mirror()));
            // The sibling file keeps its tables (down() leaves it); a server mirror is rebuilt empty.
            self::assertSame($sibling ? 1 : 0, $this->mirror()->table('orrery_settings')->where('key', 'd8_2_old_schema')->count());
        } finally {
            $primary->getSchemaBuilder()->dropIfExists('app_widgets');
            $primary->table('migrations')->where('migration', '2026_11_09_000000_create_app_widgets')->delete();
            @unlink($directory.'/2026_11_09_000000_create_app_widgets.php');
            @rmdir($directory);
        }
    }

    /**
     * Rejects: a refusal whose steps cannot be followed. `orrery:resolve --all` selects
     * only the operations that wait for an operator (awaiting_since set), so an operation in
     * progress stays open after it; the refusal must name it (its id, action and state) so that
     * `orrery:resolve <id>` can finish it, and the steps must name that command and the tick.
     */
    public function test_the_refusal_lists_the_open_operations_that_resolve_all_leaves(): void
    {
        $schema = $this->mirrorSchema();
        $this->operation('e', 'claimed');
        $id = str_pad('e', 32, '0');

        self::assertSame(0, Artisan::call('orrery:resolve', ['--all' => true]), Artisan::output());
        self::assertSame('claimed', $this->mirror()->table('orrery_operations')->where('id', $id)->value('state'), 'resolve --all leaves an operation that waits for no operator.');

        PackageSchema::useOpenOperationGuardForTesting(true);

        try {
            PackageSchema::drop($schema);
            self::fail('drop() dropped the tables of an open operation.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString($id.' (retry, claimed', $error->getMessage());
            self::assertStringContainsString('orrery:resolve <id>', $error->getMessage());
            self::assertStringContainsString('orrery:tick', $error->getMessage());
        } finally {
            PackageSchema::useOpenOperationGuardForTesting(false);
        }

        self::assertTrue($schema->hasTable('orrery_operations'));
    }

    /**
     * Rejects: an upgrade hint that names an Artisan command or option that does not
     * exist in this release (for example `orrery:install --indexes` before the release that adds it).
     */
    public function test_every_command_and_option_of_the_upgrade_steps_exists(): void
    {
        $commands = Artisan::all();
        preg_match_all('/php artisan ([a-z][a-z:-]*)((?: --[a-z-]+(?:=\S+)?)*)/', PackageSchema::UPGRADE_STEPS, $matches, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(4, count($matches), PackageSchema::UPGRADE_STEPS);

        foreach ($matches as [, $name, $options]) {
            self::assertArrayHasKey($name, $commands, "The upgrade steps name the unknown command {$name}.");
            preg_match_all('/--([a-z-]+)/', $options, $named);

            foreach ($named[1] as $option) {
                self::assertTrue($commands[$name]->getDefinition()->hasOption($option), "{$name} has no option --{$option}.");
            }
        }
    }

    private function operation(string $id, string $state): void
    {
        $now = Clock::toDecimal(Clock::now());
        $this->mirror()->table('orrery_operations')->insert([
            'id' => str_pad($id, 32, '0'), 'action' => 'retry', 'state' => $state, 'mode' => 'none',
            'job_uuid' => '00000000-0000-4000-8000-00000000d802', 'occurrence_id' => 1, 'queue_connection' => 'database', 'queue' => 'default',
            'queue_fingerprint' => str_repeat('a', 64), 'deadline' => $now, 'next_check_at' => $now, 'source' => 'cli',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * Rebuilds the dropped package tables, so the teardown's rollback finds them.
     */
    private function restore(Builder $schema): void
    {
        $missing = array_values(array_filter(PackageSchema::TABLES, static fn (string $table): bool => ! $schema->hasTable($table)));

        if ($missing !== []) {
            PackageSchema::create($schema, $missing);
        }

        PackageSchema::mark($schema->getConnection());
    }
}
