<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lifecycle\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Storage\PackageSession;
use RuntimeException;

/**
 * A job whose behaviour a test script decides. The mode is the
 * value of the table `lifecycle_script` on the primary connection, which
 * every worker process shares, else `fail`:
 * - `fail`: an explicit failure at any attempt, without an attempt
 *   exception, so the worker's tries option does not matter;
 * - `throw`: an exception (the job's tries decide a release or a failure);
 * - `complete`: the job completes;
 * - `held_worker`: attempt 1 releases, attempt 2 throws, attempt 3 completes.
 *
 * In the test process, handle() also records the job's mirror row as it is
 * while the job runs (ScriptedJob::$rows).
 */
class ScriptedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const TABLE = 'lifecycle_script';

    /** @var list<array<string, mixed>> the mirror rows that handle() saw, in this process */
    public static array $rows = [];

    public function __construct(public ?int $tries = null)
    {
    }

    public static function createScriptTable(string $mode): void
    {
        $schema = DB::connection('testbench')->getSchemaBuilder();
        $schema->dropIfExists(self::TABLE);
        $schema->create(self::TABLE, static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('mode', 32);
        });

        self::script($mode);
    }

    public static function dropScriptTable(): void
    {
        DB::connection('testbench')->getSchemaBuilder()->dropIfExists(self::TABLE);
    }

    public static function script(string $mode): void
    {
        DB::connection('testbench')->table(self::TABLE)->delete();
        DB::connection('testbench')->table(self::TABLE)->insert(['mode' => $mode]);
    }

    public function handle(): void
    {
        $uuid = strtolower((string) $this->job?->uuid());
        $row = Container::getInstance()->make(PackageSession::class)->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first();
        self::$rows[] = $row === null ? [] : (array) $row;

        $attempt = $this->attempts();

        switch ($this->mode()) {
            case 'complete':
                return;
            case 'throw':
                throw new RuntimeException("scripted exception at attempt {$attempt}");
            case 'held_worker':
                if ($attempt === 1) {
                    $this->release(0);

                    return;
                }

                if ($attempt === 2) {
                    throw new RuntimeException('scripted exception at attempt 2');
                }

                return;
            default:
                $this->fail(new RuntimeException("scripted failure at attempt {$attempt}"));
        }
    }

    private function mode(): string
    {
        $connection = DB::connection('testbench');

        if (! $connection->getSchemaBuilder()->hasTable(self::TABLE)) {
            return 'fail';
        }

        return (string) ($connection->table(self::TABLE)->value('mode') ?? 'fail');
    }
}
