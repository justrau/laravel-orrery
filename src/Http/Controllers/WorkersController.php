<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Workers\LiveWorkers;

/**
 * @phpstan-import-type WorkerRow from LiveWorkers
 */
class WorkersController extends Controller
{
    /**
     * The live workers: the rows of LiveWorkers::list(), with
     * the fields that today's dashboard reads (`name`, `queue`,
     * `seconds_ago`). A queue:listen parent is one row with its children.
     *
     * `current_job` is `{name, started_at}` or null, on each row
     * and on each child: LiveWorkers::currentJobs(). Null means unknown,
     * never idle: `reserved_by` is known only with the connector swap on. A
     * failed read of the current jobs is reported, and every row then has
     * null.
     *
     * A failed read of the workers answers a JSON 503.
     *
     * @return array<int, array<string, mixed>>|JsonResponse
     */
    public function index(LiveWorkers $workers): array|JsonResponse
    {
        $now = Clock::now();
        $list = self::watchedRead('http:workers', static fn (): array => $workers->list(), 'The workers');

        if ($list instanceof JsonResponse) {
            return $list;
        }

        // A failed read gives no current job (unknown), never a failed response.
        $jobs = Watcher::instance()->run('workers:current_jobs', static fn (): array => $workers->currentJobs($list, $now), []);
        $rows = [];

        foreach ($list as $worker) {
            $children = [];

            foreach ($worker->children as $child) {
                /** @var WorkerRow $child */
                $children[] = get_object_vars($child) + ['current_job' => $jobs[$child->worker_id] ?? null];
            }

            $rows[] = array_replace(get_object_vars($worker), ['children' => $children]) + [
                'name' => $worker->mode === LiveWorkers::LISTEN
                    ? $worker->host.':listen('.$worker->pid.')'
                    : $worker->host.':'.$worker->pid,
                'queue' => implode(',', $worker->subscribed_queues),
                'seconds_ago' => round($now - $worker->last_seen_at, 1),
                'current_job' => $jobs[$worker->worker_id] ?? null,
            ];
        }

        return $rows;
    }
}
