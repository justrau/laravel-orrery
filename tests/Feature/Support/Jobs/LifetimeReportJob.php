<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Support\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Justrau\Orrery\Lifecycle\RuntimeClock;
use Justrau\Orrery\Tests\Feature\Support\BindingLifetimeTest;
use RuntimeException;

/**
 * A job that reports the package's container instances as it sees them
 * (BindingLifetimeTest): for each binding the object id, and whether the
 * runtime map of this job has the job's own start (JobProcessing and the
 * job share the scoped map). It keeps every instance that it saw, so no
 * object id is reused in the process, and appends one JSON line per job to
 * the file that it carries.
 */
class LifetimeReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var list<object> every instance that a job of this process saw */
    private static array $kept = [];

    public function __construct(public string $path)
    {
    }

    public function handle(): void
    {
        $container = Container::getInstance();
        $ids = [];

        foreach ([...BindingLifetimeTest::SCOPED, ...BindingLifetimeTest::PROCESS] as $binding) {
            $instance = $container->make($binding);
            self::$kept[] = $instance;
            $ids[$binding] = spl_object_id($instance);
        }

        $uuid = strtolower((string) $this->job?->uuid());
        $line = json_encode(['ids' => $ids, 'runtime_started' => RuntimeClock::elapsedMs($uuid) !== null], JSON_THROW_ON_ERROR)."\n";

        if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Cannot write the lifetime report {$this->path}.");
        }
    }
}
