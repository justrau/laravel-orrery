<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Justrau\Orrery\Maintenance\RetentionSettings;
use Justrau\Orrery\Storage\PackageSession;
use stdClass;

/**
 * GET /api/settings/retention: `{global, overrides: [{connection,
 * queue, values}], errors, unmatched, effective: [{connection, queue, values:
 * {key: {minutes, rule}}}], truncated}`, as Maintenance\RetentionSettings
 * builds it. `rule` is `connection+queue`, `queue`, `connection` or
 * `global`. `unmatched` and `truncated` are null when the known queues could
 * not be read whole (also while the package refuses its mirror). The Queues
 * inspector reads it for the selected queue ("Kept for").
 */
class RetentionSettingsController extends Controller
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    public function index(RetentionSettings $settings, PackageSession $sessions): JsonResponse
    {
        $body = $settings->report($sessions->mirrorRefusal() === null);
        $overrides = [];

        foreach ($body['overrides'] as $entry) {
            // An entry without a valid key is still an object in JSON.
            $overrides[] = ['connection' => $entry['connection'], 'queue' => $entry['queue'], 'values' => $entry['values'] === [] ? new stdClass : $entry['values']];
        }

        $body['overrides'] = $overrides;

        return new JsonResponse($body, 200, [], self::JSON);
    }
}
