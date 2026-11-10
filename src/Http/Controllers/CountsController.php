<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Http\Tags;
use Justrau\Orrery\Runtime\ErrorKinds;
use Justrau\Orrery\Runtime\Watcher;
use Throwable;

/**
 * GET /api/counts: the totals of the Jobs list
 * tabs that the shell shows, without the list rows.
 *
 * - One count per status tab, each `{count, at_least}`: `pending` (status
 *   pending only; it counted pending and reserved before), `running`
 *   (reserved), `completed` (without the silenced), `silenced`, `failed`,
 *   `retrying` and `unknown`. Each count reads at most
 *   `orrery.dashboard.count_cap` + 1 index entries of the tab's own index
 *   (COUNT(*) over a LIMIT subquery, JobList::tabCount()). At the cap,
 *   `count` is the cap and `at_least` is true. No stuck count.
 * - `monitored_tags`: the number of monitored tags, with no count per tag.
 *
 * Unknown is never zero: a count whose read failed is `{count: null,
 * at_least: false}` (and `monitored_tags` null), and the watcher records
 * and reports the error. After a statement timeout or a dependency error
 * the later counts are unknown without a read. No statement selects a
 * payload.
 */
class CountsController extends Controller
{
    public const DEFAULT_CAP = 1_000_000;

    public const LISTS = ['pending', 'running', 'completed', 'silenced', 'failed', 'retrying', 'unknown'];

    public function index(JobList $jobs, Tags $tags): JsonResponse
    {
        $cap = self::cap();
        $watcher = Watcher::instance();
        $response = [];

        $stopped = false;

        foreach (self::LISTS as $list) {
            if ($stopped) {
                $response[$list] = ['count' => null, 'at_least' => false];

                continue;
            }

            $result = $watcher->attempt('http:counts_'.$list, static fn (): array => $jobs->tabCount($list, $cap));
            $value = $result->succeeded() ? $result->value() : null;
            $response[$list] = is_array($value) ? $value : ['count' => null, 'at_least' => false];
            $stopped = self::stops($result->error());
        }

        $monitored = $stopped ? null : $watcher->attempt('http:counts_monitored_tags', static fn (): int => $tags->monitoredCount());
        $response['monitored_tags'] = $monitored !== null && $monitored->succeeded() && is_int($monitored->value()) ? $monitored->value() : null;

        return new JsonResponse($response);
    }

    /**
     * Whether a failed count ends the reads: a statement timeout or a
     * dependency error of the mirror, so a failing mirror costs one timeout
     * per request, not one per count (as BatchesController::members()).
     */
    private static function stops(?Throwable $error): bool
    {
        return $error !== null && (ErrorKinds::isDependency($error) || JobList::isTimeout($error));
    }

    /**
     * `orrery.dashboard.count_cap`: a whole number of at least 1, else the
     * default (1,000,000).
     */
    public static function cap(): int
    {
        $cap = config('orrery.dashboard.count_cap', self::DEFAULT_CAP);

        if (is_int($cap) && $cap >= 1) {
            return $cap;
        }

        if (is_string($cap) && preg_match('/^[1-9]\d{0,17}$/D', $cap) === 1) {
            return (int) $cap;
        }

        return self::DEFAULT_CAP;
    }
}
