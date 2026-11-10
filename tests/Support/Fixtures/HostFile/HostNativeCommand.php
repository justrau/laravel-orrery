<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\HostFile;

use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Throwable;

/**
 * test:host-native {ops}: the application's own queue calls on `app_queue`,
 * one after the other, each with its result. `queue` is pop(), the
 * delete of the reserved job (deleteReserved(), held at IN_DELETE inside
 * its transaction), pop() and release() (deleteAndRelease()); `bulk` is
 * one bulk() of two jobs as the first
 * database operation of the process. Then it reaches AFTER_OPS, so a test
 * can keep the process (and its connection) alive, and prints one JSON
 * line: the list of [operation, ok, error class].
 */
class HostNativeCommand extends Command
{
    public const AFTER_OPS = 'host_native.after_ops';

    /**
     * Inside the transaction of the first delete (Laravel's
     * deleteReserved()): after its lockForUpdate()->find() read, before its
     * delete. The host's transaction holds its read snapshot of the file
     * (and with transaction_mode immediate its write lock) while the test
     * runs the tick; a package write to the file in between makes the
     * delete fail (busy_timeout 0).
     */
    public const IN_DELETE = 'host_native.in_delete';

    /** @var string */
    protected $signature = 'test:host-native {ops}';

    /** @var string */
    protected $description = 'Run the application queue calls on the application SQLite file (test fixture).';

    public function handle(): int
    {
        $queue = $this->laravel['queue']->connection(HostFileFixture::QUEUE);
        $results = [];
        $run = static function (string $name, callable $call) use (&$results): mixed {
            try {
                $value = $call();
                $results[] = [$name, true, null];

                return $value;
            } catch (Throwable $error) {
                $results[] = [$name, false, $error::class];

                return null;
            }
        };

        if ($this->argument('ops') === 'bulk') {
            $run('bulk', static fn () => $queue->bulk([new BasicJob, new BasicJob], '', 'default'));
        } else {
            $first = $run('pop', static fn () => $queue->pop('default'));
            $holding = false;
            $this->laravel['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$holding): void {
                // The locking read of deleteReserved(), inside its transaction.
                if ($holding && $event->connection->transactionLevel() > 0 && str_starts_with(strtolower(ltrim($event->sql)), 'select')) {
                    $holding = false;
                    Hooks::reach(self::IN_DELETE);
                }
            });
            $holding = true;
            $run('deleteReserved', static fn () => $first?->delete());
            $holding = false;
            $second = $run('pop', static fn () => $queue->pop('default'));
            $run('deleteAndRelease', static fn () => $second?->release(0));
        }

        Hooks::reach(self::AFTER_OPS);
        $this->line((string) json_encode($results));

        return self::SUCCESS;
    }
}
