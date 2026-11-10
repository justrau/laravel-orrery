<?php

namespace Justrau\Orrery\Http\Projection;

use Illuminate\Database\Query\Expression;
use Justrau\Orrery\Failures\GroupKey;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\TrustedSql;
use stdClass;
use Throwable;

/**
 * The one projection of job data in every response.
 * Controllers never serialize a model or a raw row: they pass the
 * columns here.
 *
 * - Every value goes through the data-only parser (SerializedValueParser)
 *   and the redactor (Redactor), in this order: parse, key rules, pattern
 *   rules, then truncation and the output limits. The browser never
 *   receives raw serialized PHP.
 * - Laravel's Context (`illuminate:log:context`): its `hidden` part is
 *   always {"omitted": "hidden_context"}; each value of its `data` part is
 *   parsed and redacted like the command.
 * - **No exception leaves the projection** (S-3). A parse step that throws
 *   gives {"unavailable": "error"}, a redaction step "[redaction failed]";
 *   the error is reported only through Watcher::report(), with the step and
 *   the exception class, never its message. The one exception that leaves
 *   is RedactionConfigInvalid: an invalid configured rule makes every
 *   job-data read answer 500 `redaction_config_invalid` (every method
 *   checks it first).
 * - Large values are omitted whole, never cut to a prefix: the caller reads
 *   them with bounded() (`CASE WHEN <bytes> <= <limit> THEN <column> END`),
 *   so the size decision and the value come from one row version.
 * - Stored data is never changed.
 */
class JobProjection
{
    public const PAYLOAD_BYTES = 1_048_576;

    public const EXCEPTION_BYTES = 65_536;

    public const CONTEXT_BYTES = 262_144;

    /** The stored batch options that a batch read loads; larger ones are omitted whole. */
    public const OPTIONS_BYTES = 65_536;

    /** The option keys of a batch whose lists hold callbacks (PendingBatch::registerCallback()). */
    public const BATCH_CALLBACKS = ['before', 'progress', 'then', 'catch', 'finally', 'failure'];

    /** The characters of a batch name: Laravel's job_batches.name is a string(255). */
    public const BATCH_NAME_CHARACTERS = 255;

    /** The whole detail response (fit()). */
    public const RESPONSE_BYTES = 2_097_152;

    /** The characters of a first line in a list or a cycle. */
    public const MESSAGE_CHARACTERS = 500;

    /** The fields that fit() may replace, in the order of a tie. */
    public const BUDGET_FIELDS = ['payload', 'command', 'context', 'exception'];

    /**
     * The keys of the projection's own shapes (the markers, `integer`,
     * `enum`, `serialized`, `array`, `class` and `properties`, and the `map`
     * wrapper): a natural object with only these keys is wrapped (natural()).
     */
    public const SHAPE_KEYS = [
        'omitted', 'bytes', 'unavailable', 'limit', 'closure', 'encrypted', 'ref', 'binary', 'class', 'custom',
        'integer', 'enum', 'serialized', 'array', 'properties', 'map',
    ];

    /** The mirror columns of a summary row: never payload, exception or context. */
    public const SUMMARY_COLUMNS = [
        'id', 'uuid', 'cycle', 'occurrence_id', 'name', 'type', 'connection', 'queue', 'batch_id', 'status',
        'silenced', 'attempts', 'retried_count', 'retried_count_inferred', 'tags_truncated', 'pushed_at',
        'available_at', 'reserved_at', 'finished_at', 'failed_at', 'runtime_ms', 'exception_class',
        'exception_message', 'reserved_by', 'operation_id',
    ];

    /** The top-level payload key of Laravel's Context. */
    public const CONTEXT_KEY = 'illuminate:log:context';

    public const JSON = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private readonly SerializedValueParser $parser,
        private readonly Redactor $redactor,
    ) {}

    /**
     * The select expression that loads a large column only when its byte
     * count is within the column's limit (PAYLOAD_BYTES, EXCEPTION_BYTES,
     * CONTEXT_BYTES): `CASE WHEN <column>_bytes <= <limit> THEN <column> END
     * AS <column>`. The size decision and the value come from one row
     * version, and an oversized value is never loaded. Select
     * `<column>_bytes` beside it.
     *
     * @param  'payload'|'exception'|'context'  $column
     * @return Expression<literal-string>
     */
    public static function bounded(string $column): Expression
    {
        return match ($column) {
            'payload' => TrustedSql::expression('CASE WHEN payload_bytes <= %s THEN payload END AS payload', self::PAYLOAD_BYTES),
            'exception' => TrustedSql::expression('CASE WHEN exception_bytes <= %s THEN exception END AS exception', self::EXCEPTION_BYTES),
            'context' => TrustedSql::expression('CASE WHEN context_bytes <= %s THEN context END AS context', self::CONTEXT_BYTES),
        };
    }

    /**
     * @throws RedactionConfigInvalid
     */
    public function assertValid(): void
    {
        $this->redactor->assertValid();
    }

    /**
     * The summary row of a list (4.3) from SUMMARY_COLUMNS. $extra adds the
     * parts that the list reads for the whole page: `tags`, `worker`,
     * `lease_expires_at`, `operation`, and `signals` (only on stuck rows).
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     *
     * @throws RedactionConfigInvalid
     */
    public function summary(object $row, array $extra = []): array
    {
        $this->assertValid();
        $value = static fn (string $column): mixed => $row->{$column} ?? null;

        $summary = [
            'id' => strtolower((string) $value('uuid')),
            'cycle' => self::int($value('cycle')) ?? 1,
            'occurrence_id' => self::int($value('occurrence_id')),
            'name' => (string) $value('name'),
            'type' => (string) ($value('type') ?? 'job'),
            'connection' => (string) $value('connection'),
            'queue' => (string) $value('queue'),
            'batch_id' => is_string($value('batch_id')) ? $value('batch_id') : null,
            'status' => (string) $value('status'),
            'silenced' => (bool) $value('silenced'),
            'attempts' => self::int($value('attempts')) ?? 0,
            'retried_count' => self::int($value('retried_count')) ?? 0,
            'retried_count_inferred' => (bool) $value('retried_count_inferred'),
            'tags' => $extra['tags'] ?? [],
            'tags_truncated' => (bool) $value('tags_truncated'),
            'pushed_at' => self::time($value('pushed_at')),
            'available_at' => self::time($value('available_at')),
            'reserved_at' => self::time($value('reserved_at')),
            'finished_at' => self::time($value('finished_at')),
            'failed_at' => self::time($value('failed_at')),
            'runtime_ms' => self::int($value('runtime_ms')),
            'exception_class' => is_string($value('exception_class')) ? $value('exception_class') : null,
            'exception_message' => $this->message(is_string($value('exception_message')) ? $value('exception_message') : null),
            'worker' => $extra['worker'] ?? null,
            'lease_expires_at' => $extra['lease_expires_at'] ?? null,
            'operation' => $extra['operation'] ?? null,
        ];

        if (array_key_exists('signals', $extra)) {
            $summary['signals'] = $extra['signals'];
        }

        return $summary;
    }

    /**
     * The first line of a message, redacted as a whole value first, then
     * cut to MESSAGE_CHARACTERS: a list row, a cycle, a history entry, a
     * failure group.
     *
     * @throws RedactionConfigInvalid
     */
    public function message(?string $message): ?string
    {
        $this->assertValid();

        if ($message === null) {
            return null;
        }

        $redacted = $this->redactText($message);

        if ($redacted === Redactor::FAILED) {
            return $redacted;
        }

        return mb_substr((string) GroupKey::firstLine($redacted), 0, self::MESSAGE_CHARACTERS);
    }

    /**
     * The full exception text: redacted as a whole when it has at most
     * EXCEPTION_BYTES bytes, else {"omitted": "too_large", "bytes": n}.
     * $bytes is the stored byte count (`exception_bytes`).
     *
     * @return string|array{omitted: string, bytes: int}|null
     *
     * @throws RedactionConfigInvalid
     */
    public function exception(?string $text, ?int $bytes): string|array|null
    {
        $this->assertValid();
        $bytes ??= $text === null ? null : strlen($text);

        if ($bytes !== null && ($bytes > self::EXCEPTION_BYTES || ($text !== null && strlen($text) > self::EXCEPTION_BYTES))) {
            return ['omitted' => 'too_large', 'bytes' => max($bytes, $text === null ? 0 : strlen($text))];
        }

        return $text === null ? null : $this->redactText($text);
    }

    /**
     * The payload envelope with `data.command` replaced by the command
     * projection, and the command projection, under one budget. $json is
     * null when the bounded read left it out ($bytes over PAYLOAD_BYTES).
     *
     * @return array{payload: mixed, command: mixed}
     *
     * @throws RedactionConfigInvalid
     */
    public function payload(?string $json, ?int $bytes): array
    {
        $this->assertValid();

        if ($json === null || ($bytes !== null && $bytes > self::PAYLOAD_BYTES)) {
            $omitted = ['omitted' => 'too_large', 'bytes' => (int) $bytes];

            return ['payload' => $omitted, 'command' => $omitted];
        }

        $budget = new ProjectionBudget;

        try {
            $budget->input(strlen($json));
        } catch (ProjectionLimit $limit) {
            return ['payload' => $limit->marker(), 'command' => $limit->marker()];
        }

        [$envelope, $valid] = self::decode($json);

        if (! $valid) {
            return ['payload' => ['unavailable' => 'invalid_json'], 'command' => ['unavailable' => 'invalid_json']];
        }

        $data = $envelope instanceof stdClass ? ($envelope->data ?? null) : null;
        $hasCommand = $data instanceof stdClass && property_exists($data, 'command');

        try {
            $command = $hasCommand ? $this->parser->command($data->command, $budget) : ['unavailable' => 'unknown_format'];
        } catch (Throwable $error) {
            $this->failed('projection:parse', 'command', $error);
            $command = ['unavailable' => 'error'];
        }

        try {
            $projected = $this->envelope($envelope, $budget, true, $hasCommand ? $command : null);
        } catch (ProjectionLimit $limit) {
            $projected = $limit->marker();
        } catch (Throwable $error) {
            $this->failed('projection:parse', 'envelope', $error);
            $projected = ['unavailable' => 'error'];
        }

        $payload = $this->redact($projected);
        $data = $payload instanceof stdClass ? ($payload->data ?? null) : null;

        // The redacted payload holds the command projection: it is redacted once.
        return ['payload' => $payload, 'command' => $hasCommand && $data instanceof stdClass && property_exists($data, 'command') ? $data->command : $this->redact($command)];
    }

    /**
     * The JSON as objects (depth 64), and whether it was valid. A key that
     * starts with a NUL byte (an array cast of an object with a protected or
     * private property, `\u0000*\u0000name`) is valid JSON that no PHP
     * object can hold (JSON_ERROR_INVALID_PROPERTY_NAME): it is
     * decoded as arrays, and every NUL byte of a key is shown as the text
     * `\0`, as PHP prints such a key.
     *
     * @return array{0: mixed, 1: bool}
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, false, 64);

        if (json_last_error() === JSON_ERROR_NONE) {
            return [$decoded, true];
        }

        if (json_last_error() !== JSON_ERROR_INVALID_PROPERTY_NAME) {
            return [null, false];
        }

        $arrays = json_decode($json, true, 64);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [null, false];
        }

        return [self::objects($arrays), true];
    }

    /**
     * The tree of json_decode(…, true) with stdClass objects again: an array
     * whose keys are not 0..n-1 in order was an object. In this fallback
     * only, `{}` and an object with exactly the keys 0..n-1 show as lists.
     */
    private static function objects(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::objects($item), $value);
        }

        $object = new stdClass;

        foreach ($value as $key => $item) {
            $object->{str_replace("\0", '\\0', (string) $key)} = self::objects($item);
        }

        return $object;
    }

    /**
     * The exception context (the JSON of $e->context()): decoded, every
     * string under the string rule, redacted; over CONTEXT_BYTES,
     * {"omitted": "too_large", "bytes": n}.
     *
     * @throws RedactionConfigInvalid
     */
    public function context(?string $json, ?int $bytes): mixed
    {
        $this->assertValid();
        $bytes ??= $json === null ? null : strlen($json);

        if ($bytes !== null && ($bytes > self::CONTEXT_BYTES || ($json !== null && strlen($json) > self::CONTEXT_BYTES))) {
            return ['omitted' => 'too_large', 'bytes' => $bytes];
        }

        if ($json === null) {
            return null;
        }

        [$decoded, $valid] = self::decode($json);

        if (! $valid) {
            return ['unavailable' => 'invalid_json'];
        }

        try {
            $projected = $this->envelope($decoded, new ProjectionBudget, false, null);
        } catch (ProjectionLimit $limit) {
            $projected = $limit->marker();
        } catch (Throwable $error) {
            $this->failed('projection:parse', 'context', $error);
            $projected = ['unavailable' => 'error'];
        }

        return $this->redact($projected);
    }

    /**
     * A serialized value (for example batch options): parsed data-only,
     * never unserialized, and redacted.
     *
     * @throws RedactionConfigInvalid
     */
    public function serialized(?string $value): mixed
    {
        $this->assertValid();

        if ($value === null) {
            return null;
        }

        try {
            $parsed = $this->parser->project($value, new ProjectionBudget);
        } catch (Throwable $error) {
            $this->failed('projection:parse', 'serialized', $error);
            $parsed = ['unavailable' => 'error'];
        }

        return $this->redact($parsed);
    }

    /**
     * A batch of `GET /api/batches` and `GET /api/batches/{id}`:
     * `{id, name, total_jobs, pending_jobs, processed_jobs,
     * failed_jobs, progress, created_at, cancelled_at, finished_at,
     * options}`, snake_case, times as epoch seconds.
     *
     * - $row has the columns of DatabaseBatchRepository::COLUMNS, `options`
     *   (the stored text after Laravel's Postgres base64 rule, or null when
     *   the bounded read left it out) and `options_bytes` (the stored byte
     *   count).
     * - `name` goes through the pattern rules as a whole, then is cut to
     *   BATCH_NAME_CHARACTERS (free text that the UI never
     *   sends back).
     * - `processed_jobs` and `progress` follow Laravel's Batch:
     *   total − pending, and round(processed / total × 100), 0 for an
     *   empty batch; null when a count is unknown.
     * - `options` is parsed data-only and redacted (serialized()), then its
     *   arrays are made plain (plain()): `{connection, queue, allowFailures,
     *   …}`; every element of a callback list is {"closure": true}
     *   (callbacks()); over OPTIONS_BYTES it is
     *   {"omitted": "too_large", "bytes": n}.
     *
     * @return array<string, mixed>
     *
     * @throws RedactionConfigInvalid
     */
    public function batch(object $row): array
    {
        $this->assertValid();
        $value = static fn (string $column): mixed => $row->{$column} ?? null;
        $total = self::int($value('total_jobs'));
        $pending = self::int($value('pending_jobs'));
        $processed = $total !== null && $pending !== null ? $total - $pending : null;
        $name = is_string($value('name')) ? $this->text($value('name')) : null;

        return [
            'id' => (string) $value('id'),
            'name' => $name === null ? null : mb_substr($name, 0, self::BATCH_NAME_CHARACTERS),
            'total_jobs' => $total,
            'pending_jobs' => $pending,
            'processed_jobs' => $processed,
            'failed_jobs' => self::int($value('failed_jobs')),
            'progress' => $total === null || $processed === null ? null : ($total > 0 ? (int) round($processed / $total * 100) : 0),
            'created_at' => self::time($value('created_at')),
            'cancelled_at' => self::time($value('cancelled_at')),
            'finished_at' => self::time($value('finished_at')),
            'options' => $this->batchOptions(is_string($value('options')) ? $value('options') : null, self::int($value('options_bytes'))),
        ];
    }

    /**
     * Free text through the pattern rules (for example a batch name).
     *
     * @throws RedactionConfigInvalid
     */
    public function text(?string $text): ?string
    {
        $this->assertValid();

        return $text === null ? null : $this->redactText($text);
    }

    /**
     * The batch options: omitted whole over OPTIONS_BYTES, else parsed
     * data-only and redacted.
     *
     * @throws RedactionConfigInvalid
     */
    private function batchOptions(?string $options, ?int $bytes): mixed
    {
        $bytes ??= $options === null ? null : strlen($options);

        if ($bytes !== null && ($bytes > self::OPTIONS_BYTES || $options === null || strlen($options) > self::OPTIONS_BYTES)) {
            return ['omitted' => 'too_large', 'bytes' => max($bytes, $options === null ? 0 : strlen($options))];
        }

        return self::plain(self::callbacks($this->serialized($options)));
    }

    /**
     * Every element of the callback lists of the batch options
     * (BATCH_CALLBACKS) becomes {"closure": true}, whatever it holds:
     * PendingBatch::registerCallback() stores a closure as a
     * SerializableClosure, but an invokable object, an `[$object,
     * 'method']` pair or a `'Class@method'` string as it is, and none of
     * them is shown. It works on the parsed and redacted tree, before
     * plain(), so the options are masked whatever plain() gives (a key that
     * is not valid UTF-8 keeps the tree). A callback value that is not an
     * array node becomes one element.
     */
    private static function callbacks(mixed $tree): mixed
    {
        if (! is_array($tree) || array_keys($tree) !== ['array'] || ! is_array($tree['array'])) {
            return $tree;
        }

        foreach ($tree['array'] as $index => $entry) {
            if (! is_array($entry) || ! in_array($entry['key'] ?? null, self::BATCH_CALLBACKS, true)) {
                continue;
            }

            $value = $entry['value'] ?? null;
            $elements = is_array($value) && array_keys($value) === ['array'] && is_array($value['array'])
                ? array_values(array_map(static fn (mixed $element): array => ['key' => is_array($element) && (is_int($element['key'] ?? null) || is_string($element['key'] ?? null)) ? $element['key'] : 0, 'value' => ['closure' => true]], $value['array']))
                : [['key' => 0, 'value' => ['closure' => true]]];
            $tree['array'][$index]['value'] = ['array' => $elements];
        }

        return $tree;
    }

    /**
     * The parser's array nodes ({"array": [{key, value}, …]}) as plain JSON
     * data: a list when the keys are 0 … n − 1 in order, else an object. The
     * batch options then read as Laravel's `options` array
     * (`options.queue`); object and marker nodes stay as they are, with their
     * values made plain.
     */
    private static function plain(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_keys($value) === ['array'] && is_array($value['array']) && array_is_list($value['array'])) {
            $plain = [];

            foreach ($value['array'] as $entry) {
                if (! is_array($entry) || ! array_key_exists('key', $entry) || ! array_key_exists('value', $entry) || ! (is_int($entry['key']) || is_string($entry['key']))) {
                    return $value;
                }

                $plain[$entry['key']] = self::plain($entry['value']);
            }

            return array_is_list($plain) ? $plain : (object) $plain;
        }

        return array_map(self::plain(...), $value);
    }

    /**
     * The response budget (4.5): while the encoded response is larger than
     * RESPONSE_BYTES, the largest remaining of BUDGET_FIELDS (a tie in
     * their order) becomes {"omitted": "response_budget", "bytes": n}.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    public function fit(array $response): array
    {
        $size = self::size($response);
        $remaining = array_values(array_filter(self::BUDGET_FIELDS, static fn (string $field): bool => array_key_exists($field, $response)));

        while ($size > self::RESPONSE_BYTES && $remaining !== []) {
            $field = $remaining[0];
            $largest = -1;

            // The largest remaining field; a tie keeps the BUDGET_FIELDS order.
            foreach ($remaining as $candidate) {
                $candidateSize = self::size($response[$candidate]);

                if ($candidateSize > $largest) {
                    [$field, $largest] = [$candidate, $candidateSize];
                }
            }

            $response[$field] = ['omitted' => 'response_budget', 'bytes' => $largest];
            $remaining = array_values(array_diff($remaining, [$field]));
            $size = self::size($response);
        }

        return $response;
    }

    /**
     * The envelope walk: every string under the string rule; at the top of
     * a payload, Laravel's Context and the command.
     *
     * @throws ProjectionLimit
     */
    private function envelope(mixed $value, ProjectionBudget $budget, bool $payload, mixed $command): mixed
    {
        if ($value instanceof stdClass) {
            $out = new stdClass;

            foreach (get_object_vars($value) as $key => $item) {
                if ($payload && $key === self::CONTEXT_KEY) {
                    $out->{$key} = $this->laravelContext($item, $budget);
                } elseif ($payload && $key === 'data' && $item instanceof stdClass && $command !== null) {
                    // The command is already projected: it is never parsed a second time under the same budget.
                    $data = new stdClass;

                    foreach (get_object_vars($item) as $dataKey => $dataItem) {
                        $data->{$dataKey} = $dataKey === 'command' ? $command : $this->envelope($dataItem, $budget, false, null);
                    }

                    $out->{$key} = $data;
                } else {
                    $out->{$key} = $this->envelope($item, $budget, false, null);
                }
            }

            return self::natural($out);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->envelope($item, $budget, false, null), $value);
        }

        if (is_string($value)) {
            return $this->parser->string($value, $budget);
        }

        return $value;
    }

    /**
     * A natural JSON object as the dashboard reads it: an object whose keys
     * are all keys of a projection shape (a marker, an exact integer, an
     * enum, a serialized array or object, or this wrapper) is wrapped as
     * {"map": {...}}, so real data such as {"ref": 48213} is never drawn as a
     * marker. The parser's own shapes are PHP arrays, never wrapped.
     *
     * @return stdClass|array{map: stdClass}
     */
    private static function natural(stdClass $object): stdClass|array
    {
        $keys = array_keys(get_object_vars($object));

        return $keys !== [] && array_diff($keys, self::SHAPE_KEYS) === [] ? ['map' => $object] : $object;
    }

    /**
     * Laravel's Context: `hidden` is never shown; `data` values are parsed.
     * Any other shape is hidden whole.
     *
     * @throws ProjectionLimit
     */
    private function laravelContext(mixed $context, ProjectionBudget $budget): mixed
    {
        if (! $context instanceof stdClass) {
            return ['omitted' => 'hidden_context'];
        }

        $out = new stdClass;

        foreach (get_object_vars($context) as $key => $part) {
            $out->{$key} = $key === 'hidden' ? ['omitted' => 'hidden_context'] : $this->envelope($part, $budget, false, null);
        }

        return $out;
    }

    private function redactText(string $text): string
    {
        try {
            return $this->redactor->text($text);
        } catch (RedactionConfigInvalid $invalid) {
            throw $invalid;
        } catch (Throwable $error) {
            $this->failed('projection:redact', 'text', $error);

            return Redactor::FAILED;
        }
    }

    private function redact(mixed $tree): mixed
    {
        try {
            return $this->redactor->tree($tree);
        } catch (RedactionConfigInvalid $invalid) {
            throw $invalid;
        } catch (Throwable $error) {
            $this->failed('projection:redact', 'tree', $error);

            return Redactor::FAILED;
        }
    }

    private function failed(string $operation, string $step, Throwable $error): void
    {
        try {
            // The step and the class only: the message or trace can carry job values (S-3).
            Watcher::instance()->report($operation, "The {$step} step threw ".$error::class.'.');
        } catch (Throwable) {
            // Nothing is left to report to.
        }
    }

    private static function size(mixed $value): int
    {
        $json = json_encode($value, self::JSON | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? 0 : strlen($json);
    }

    private static function time(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
