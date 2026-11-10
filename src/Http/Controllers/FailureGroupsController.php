<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Justrau\Orrery\Failures\GroupKey;
use Justrau\Orrery\Failures\GroupReads;
use Justrau\Orrery\Failures\InvalidFailureRequest;
use Justrau\Orrery\Http\Projection\JobProjection;
use Justrau\Orrery\Http\Projection\RedactionConfigInvalid;
use Justrau\Orrery\Http\QueryTooLong;
use Justrau\Orrery\Http\RawQuery;
use Justrau\Orrery\Storage\Clock;

/**
 * The failure groups.
 *
 * GET /api/failures/groups?queue=&from=&to=&offset=
 * GET /api/failures/groups/{key}?queue=&from=&to=&offset=
 *
 * Both read through the stored key of the failed rows (GroupReads): a
 * head scan finds the groups, and bounded per-key reads
 * (orrery.dashboard.group_count_cap) give their counts and times. Every
 * failed row has its key (the fallback scan is gone).
 * `retention_from` is the retention horizon of failed rows (as_of -
 * trim.failed minutes): older failures are certainly gone, and newer gaps
 * cannot be ruled out. An invalid scope, key or offset gives a JSON 422.
 * The parameters come from the raw query string (RawQuery), so a host's
 * TrimStrings never changes a queue name.
 *
 * The detail also returns `selection`: the newest 200 pairs {uuid,
 * occurrence_id, failed_at}, whatever the offset, and `selection_total`. A
 * key without members in scope gives `group: null` with `complete: true`.
 * `jobs` stays the display page of 50; its offset must be below the group
 * count cap.
 *
 * The text fields (`message`, `latest_trace`) are redacted;
 * an invalid redaction configuration answers 500
 * `redaction_config_invalid`, checked before the read. The reads run in
 * the watcher: a failed read answers a JSON 503 (`read_timeout` or
 * `read_failed`).
 */
class FailureGroupsController extends Controller
{
    use AnswersFailureRequests;

    private const OFFSET = '/^\d{1,6}$/D';

    public function index(Request $request): JsonResponse
    {
        try {
            $query = RawQuery::of($request);
        } catch (QueryTooLong $tooLong) {
            return $tooLong->response();
        }

        try {
            $scope = self::scopeFrom($query['queue'] ?? null, $query['from'] ?? null, $query['to'] ?? null);
            $offset = self::offset($query['offset'] ?? null);
        } catch (InvalidFailureRequest $error) {
            return self::invalid($error);
        }

        try {
            self::assertRedactionValid();
        } catch (RedactionConfigInvalid $error) {
            return self::redactionInvalid($error);
        }

        $asOf = Clock::now();
        $retentionFrom = self::retentionFrom($asOf);
        $groups = self::watchedRead('http:failure_groups', static fn (): array => GroupReads::index($scope, $offset, $asOf, $retentionFrom), 'The failure groups');

        if ($groups instanceof JsonResponse) {
            return $groups;
        }

        return self::json([
            'as_of' => $asOf,
            'retention_from' => $retentionFrom,
            'scope' => $scope->toArray(),
        ] + $groups);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        try {
            $query = RawQuery::of($request);
        } catch (QueryTooLong $tooLong) {
            return $tooLong->response();
        }

        try {
            if (! GroupKey::valid($key)) {
                throw new InvalidFailureRequest('The group key is 64 lowercase hexadecimal characters.');
            }

            $scope = self::scopeFrom($query['queue'] ?? null, $query['from'] ?? null, $query['to'] ?? null);
            $offset = self::offset($query['offset'] ?? null);
            self::assertRedactionValid();
        } catch (InvalidFailureRequest $error) {
            return self::invalid($error);
        } catch (RedactionConfigInvalid $error) {
            return self::redactionInvalid($error);
        }

        $asOf = Clock::now();
        $retentionFrom = self::retentionFrom($asOf);
        // The offset check of detail() is a request error, never a read failure: it leaves the watched read as a value.
        $detail = self::watchedRead('http:failure_groups', static function () use ($scope, $key, $offset, $asOf, $retentionFrom): array|InvalidFailureRequest {
            try {
                return GroupReads::detail($scope, $key, $offset, $asOf, $retentionFrom);
            } catch (InvalidFailureRequest $error) {
                return $error;
            }
        }, 'The failure group');

        if ($detail instanceof JsonResponse) {
            return $detail;
        }

        if ($detail instanceof InvalidFailureRequest) {
            return self::invalid($detail);
        }

        return self::json([
            'as_of' => $asOf,
            'retention_from' => $retentionFrom,
            'scope' => $scope->toArray(),
        ] + $detail);
    }

    /**
     * The redaction rules are checked before the watched read, so an invalid
     * configuration answers its own 500, never a 503 `read_failed`.
     *
     * @throws RedactionConfigInvalid
     */
    private static function assertRedactionValid(): void
    {
        Container::getInstance()->make(JobProjection::class)->assertValid();
    }

    /**
     * @throws InvalidFailureRequest
     */
    private static function offset(mixed $offset): int
    {
        if ($offset === null || $offset === '') {
            return 0;
        }

        if (! is_string($offset) || preg_match(self::OFFSET, $offset) !== 1) {
            throw new InvalidFailureRequest('offset is a whole number of 0 or more.');
        }

        return (int) $offset;
    }
}
