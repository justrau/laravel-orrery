<?php

namespace Justrau\Orrery\Observation;

/**
 * The payload identity of a queue job: the
 * payload's canonical uuid.
 */
final class PayloadIdentity
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    /**
     * The payload's canonical uuid (the `uuid`, else the top-level `id`),
     * lower-cased: the rule of Observation::fromPayload(). Null when the
     * payload has neither, so it is not mirrored.
     */
    public static function uuidOf(mixed $payload): ?string
    {
        if (! is_string($payload)) {
            return null;
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return null;
        }

        foreach (['uuid', 'id'] as $key) {
            $value = $decoded[$key] ?? null;

            if (is_string($value) && preg_match(self::UUID_PATTERN, $value) === 1) {
                return strtolower($value);
            }
        }

        return null;
    }
}
