<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Http\Projection\JobProjection;
use Justrau\Orrery\Http\Projection\ProjectionBudget;
use Justrau\Orrery\Http\Projection\RedactionConfigInvalid;
use Justrau\Orrery\Http\Projection\Redactor;
use Justrau\Orrery\Http\Projection\SerializedValueParser;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Tests\Feature\Projection\Jobs\CanaryJob;
use Justrau\Orrery\Tests\Feature\Projection\Jobs\CanaryMailable;
use Justrau\Orrery\Tests\Feature\Projection\Jobs\CanaryNotification;
use Justrau\Orrery\Tests\Feature\Projection\Jobs\CanaryUser;
use Justrau\Orrery\Tests\Feature\Projection\Jobs\EncryptedCanaryJob;
use Justrau\Orrery\Tests\Feature\Projection\Jobs\LargeJob;
use Justrau\Orrery\Tests\TestCase;
use Laravel\SerializableClosure\SerializableClosure;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * The job projection: every job value of a
 * response goes through the data-only parser and the redactor; raw
 * serialized PHP, hidden Context and the canaries never
 * appear in the encoded output.
 */
final class JobProjectionTest extends TestCase
{
    /** The secrets of CanaryJob, which no projection output may contain. */
    private const JOB_CANARIES = [
        'canary-public-password',
        'canary-protected-apikey',
        'canary-private-secret',
        'canary-array-token',
        'canary-header-value',
        'canary-url-password',
        'canary-large-end',
    ];

    /**
     * Every position of a real job's payload: the command's public,
     * protected and private properties, an array, a header record, a URL
     * password, a string over 64 KB, a top-level payload key, and Laravel's
     * Context (visible and hidden).
     *
     * Rejects: a projection that returns the raw payload, raw serialized
     * PHP, the hidden Context, or any canary.
     */
    public function test_a_real_job_payload_has_no_canary(): void
    {
        Queue::createPayloadUsing(static fn (): array => ['secret_token' => 'canary-top-level']);
        Context::add('api_token', 'canary-context-visible');
        Context::add('request_id', 'req-plain-123');
        Context::addHidden('internal', 'canary-context-hidden');

        $row = $this->push(new CanaryJob(CanaryUser::withKey(7)));
        $projected = $this->projection()->payload($row->payload, (int) $row->payload_bytes);
        $json = self::encode($projected);

        foreach ([...self::JOB_CANARIES, 'canary-top-level', 'canary-context-visible', 'canary-context-hidden'] as $canary) {
            self::assertStringContainsString($canary, $row->payload, "The stored payload keeps {$canary}.");
            self::assertStringNotContainsString($canary, $json, $canary);
        }

        self::assertStringNotContainsString('s:22:"canary', $json, 'No serialized PHP leaves the projection.');
        self::assertStringNotContainsString('O:', $json, 'No serialized PHP leaves the projection.');

        $payload = self::plain($projected['payload']);
        $command = self::plain($projected['command']);
        self::assertSame($command, $payload['data']['command'], 'data.command is the command projection.');
        self::assertSame(CanaryJob::class, $command['class']);
        self::assertSame(['omitted' => 'hidden_context'], $payload['illuminate:log:context']['hidden']);
        self::assertSame(['serialized' => 'req-plain-123'], $payload['illuminate:log:context']['data']['request_id'], 'A visible Context value is parsed.');
        self::assertSame('[redacted]', $payload['secret_token']);

        $properties = [];

        foreach ($command['properties'] as $property) {
            $properties[$property['name']] = $property;
        }

        self::assertSame(['name' => 'secret', 'visibility' => 'private', 'declared_in' => CanaryJob::class, 'value' => '[redacted]'], $properties['secret']);
        self::assertSame('protected', $properties['apiKey']['visibility']);
        self::assertSame('plain label', $properties['label']['value'], 'A value without a secret is kept.');
        self::assertSame(['omitted' => 'string_too_large', 'bytes' => 70_024], $properties['large']['value']);
        self::assertSame('Connect to mysql://[redacted]@db/app', $properties['note']['value']);
        self::assertSame('Illuminate\\Contracts\\Database\\ModelIdentifier', $properties['user']['value']['class'], 'A model is its ModelIdentifier.');
    }

    /**
     * A signed CallQueuedClosure that captures a password.
     *
     * Rejects: a closure whose captured variables or inner serialized text
     * reach the output.
     */
    public function test_a_queued_closure_is_opaque(): void
    {
        $password = 'canary-closure-secret';
        $row = $this->push(static function () use ($password): string {
            return $password;
        });

        self::assertStringContainsString('Signed', $row->payload, 'The closure is signed.');
        $json = self::encode($this->projection()->payload($row->payload, (int) $row->payload_bytes));

        self::assertStringNotContainsString('canary-closure-secret', $json);
        self::assertStringNotContainsString('function', $json);
        self::assertStringContainsString('"name":"closure","visibility":"public","value":{"closure":true}', $json);
    }

    /**
     * A queued mailable, a notification to a Slack webhook route of an
     * AnonymousNotifiable, and an encrypted job.
     *
     * Rejects: secrets of framework commands, the webhook path of a route,
     * or a decrypted command.
     */
    public function test_framework_commands(): void
    {
        $mail = $this->push(new SendQueuedMailable(new CanaryMailable));
        $notification = $this->push(new SendQueuedNotifications((new AnonymousNotifiable)->route('slack', 'https://hooks.slack.com/services/T0/B0/canary-slack-route'), new CanaryNotification, ['slack']));
        $encrypted = $this->push(new EncryptedCanaryJob);

        $mailJson = self::encode($this->projection()->payload($mail->payload, (int) $mail->payload_bytes));
        self::assertStringNotContainsString('canary-mail-token', $mailJson);
        self::assertStringContainsString('plain greeting', $mailJson);

        $notificationJson = self::encode($this->projection()->payload($notification->payload, (int) $notification->payload_bytes));
        self::assertStringNotContainsString('canary-slack-route', $notificationJson);
        self::assertStringNotContainsString('canary-notification-secret', $notificationJson);
        self::assertStringContainsString('hooks.slack.com/services/[redacted]', $notificationJson);

        $projected = $this->projection()->payload($encrypted->payload, (int) $encrypted->payload_bytes);
        self::assertSame(['encrypted' => true], $projected['command']);
        self::assertStringNotContainsString('canary-encrypted-note', self::encode($projected));
    }

    /**
     * Ordinary large jobs that fit the budget once: 12,000 integers (about
     * 12,000 nodes of the 20,000), and a 577 KB nested serialized string
     * (about 1.2 MB of the 2 MB work budget).
     *
     * Rejects: a payload whose envelope parses data.command a second time
     * under the same budget (the payload then answers `unavailable`).
     *
     * @return array<string, array{\Closure(): LargeJob}>
     */
    public static function largeJobs(): array
    {
        return [
            '12,000 integers' => [static fn (): LargeJob => new LargeJob(range(1, 12_000))],
            'a 577 KB nested string' => [static fn (): LargeJob => new LargeJob([], serialize(array_fill(0, 13_000, str_repeat('x', 30))))],
        ];
    }

    /**
     * @param  \Closure(): LargeJob  $job
     */
    #[DataProvider('largeJobs')]
    public function test_a_large_job_parses_its_command_once(\Closure $job): void
    {
        $row = $this->push($job());
        $projected = $this->projection()->payload($row->payload, (int) $row->payload_bytes);
        $payload = self::plain($projected['payload']);
        $command = self::plain($projected['command']);

        self::assertSame(LargeJob::class, $command['class'] ?? null, 'The command parsed.');
        self::assertSame($command, $payload['data']['command'] ?? null, 'data.command is the command projection.');
        self::assertSame(LargeJob::class, $payload['displayName'] ?? null);
        self::assertStringNotContainsString('"unavailable"', self::encode($projected));
    }

    /**
     * Rejects: an oversized payload decoded (or cut to a prefix).
     */
    public function test_an_oversized_payload_is_omitted_whole(): void
    {
        $projected = $this->projection()->payload(null, 2_000_000);

        self::assertSame(['omitted' => 'too_large', 'bytes' => 2_000_000], $projected['payload']);
        self::assertSame(['omitted' => 'too_large', 'bytes' => 2_000_000], $projected['command']);
        self::assertSame(['unavailable' => 'limit', 'limit' => 'input'], $this->projection()->payload('"'.str_repeat('a', ProjectionBudget::INPUT_BYTES).'"', 10)['payload']);
        self::assertSame(['unavailable' => 'invalid_json'], $this->projection()->payload('{oops', 5)['payload']);
    }

    /**
     * The exception text: redacted as a whole, omitted whole above 64 KB.
     *
     * Rejects: a prefix of an oversized text, a limit in characters instead
     * of bytes, or patterns applied after a cut.
     */
    public function test_exception_text(): void
    {
        $projection = $this->projection();
        $text = "RuntimeException: Login failed password=canary-exc in /app/Job.php:12\n#0 Bearer canary-trace-bearer";

        $redacted = $projection->exception($text, strlen($text));
        self::assertIsString($redacted);
        self::assertStringNotContainsString('canary', $redacted);
        self::assertStringContainsString('#0 Bearer [redacted]', $redacted);

        $atLimit = str_repeat('ü', 32_767).'xx';
        self::assertSame(65_536, strlen($atLimit));
        self::assertSame($atLimit, $projection->exception($atLimit, 65_536));
        self::assertSame(['omitted' => 'too_large', 'bytes' => 65_537], $projection->exception(null, 65_537));
        self::assertSame(['omitted' => 'too_large', 'bytes' => 65_537], $projection->exception($atLimit.'y', 65_537));
        self::assertNull($projection->exception(null, null));

        $end = str_repeat('a', 65_000).' mysql://root:canary-at-the-end@db';
        self::assertStringNotContainsString('canary-at-the-end', (string) json_encode($projection->exception($end, strlen($end))));
    }

    /**
     * The first line of a message, redacted before the cut to 500
     * characters.
     *
     * Rejects: a cut before the redaction (the URL password straddles the
     * cut, so a cut prefix has no "@" to match), or another line.
     */
    public function test_message_first_line(): void
    {
        $projection = $this->projection();
        $message = str_repeat('a', 480).' mysql://root:canary-straddle-secret-value@db'."\nsecond line";

        $line = $projection->message($message);
        self::assertIsString($line);
        self::assertStringNotContainsString('canary', $line);
        self::assertStringNotContainsString('second line', $line);
        self::assertLessThanOrEqual(500, mb_strlen($line));
        self::assertSame('Plain', $projection->message("Plain\r\nnext"));
        self::assertNull($projection->message(null));
    }

    /**
     * The exception context (JSON), a batch option and a batch name.
     *
     * Rejects: context, options or names shown without redaction, or batch
     * options unserialized.
     */
    public function test_context_options_and_text(): void
    {
        $projection = $this->projection();
        $context = (string) json_encode(['password' => 'canary-ctx', 'note' => 'Bearer canary-ctx-bearer', 'nested' => serialize(['api_key' => 'canary-ctx-nested'])]);

        $projectedContext = self::encode($projection->context($context, strlen($context)));
        self::assertStringNotContainsString('canary', $projectedContext);
        self::assertStringContainsString('"nested":{"serialized":{"array":', $projectedContext);
        self::assertSame(['omitted' => 'too_large', 'bytes' => 262_145], $projection->context(null, 262_145));

        $secret = 'canary-batch-closure';
        $options = serialize(['queue' => 'high', 'api_key' => 'canary-batch-option', 'then' => [new SerializableClosure(static fn (): string => $secret)]]);
        $projectedOptions = self::encode($projection->serialized($options));
        self::assertStringNotContainsString('canary', $projectedOptions);
        self::assertStringContainsString('"high"', $projectedOptions);
        self::assertStringContainsString('{"closure":true}', $projectedOptions);

        self::assertSame('Import Bearer [redacted]', $projection->text('Import Bearer canary-name'));
        self::assertNull($projection->text(null));
    }

    /**
     * A natural JSON object of a payload or a context with only the keys of
     * a projection shape (a marker, an exact integer, a serialized array) is
     * wrapped as {"map": {...}}, so the dashboard never draws real data as a
     * marker. An object with any other key stays as it is.
     *
     * Rejects: real data `{"ref": 48213}` sent bare (the SPA shows "Reference
     * to value #48213 · not expanded"), a wrapped object whose own key is
     * `map` that reads back one level too shallow, a wrap of objects that
     * hold another key, and a wrap of the parser's own markers.
     */
    public function test_a_natural_object_with_only_reserved_keys_is_wrapped(): void
    {
        $projection = $this->projection();
        $context = (string) json_encode([
            'marker' => ['ref' => 48213],
            'integer' => ['integer' => '12'],
            'omitted' => ['omitted' => 'too_large', 'bytes' => 5],
            'own_map' => ['map' => ['ref' => 1]],
            'plain' => ['ref' => 1, 'note' => 'x'],
            'empty' => new \stdClass,
            'nested' => serialize(['a' => 1]),
        ]);

        $projected = self::encode($projection->context($context, strlen($context)));

        self::assertStringContainsString('"marker":{"map":{"ref":48213}}', $projected);
        self::assertStringContainsString('"integer":{"map":{"integer":"12"}}', $projected);
        self::assertStringContainsString('"omitted":{"map":{"omitted":"too_large","bytes":5}}', $projected);
        self::assertStringContainsString('"own_map":{"map":{"map":{"map":{"ref":1}}}}', $projected);
        self::assertStringContainsString('"plain":{"ref":1,"note":"x"}', $projected);
        self::assertStringContainsString('"empty":{}', $projected);
        self::assertStringContainsString('"nested":{"serialized":{"array":', $projected);
    }

    /**
     * Rejects: valid JSON read as "not valid JSON" because a key starts with a NUL byte: an array
     * cast of an object with a protected property in an exception's context() gives `\u0000*\u0000name`, which no
     * PHP object can hold. The context and the payload are projected; the NUL bytes of a key show as `\0`.
     * Still passes: a projection that drops such a key instead of showing it (the other keys are asserted).
     */
    public function test_a_key_that_starts_with_a_nul_byte_is_valid_json(): void
    {
        $projection = $this->projection();
        $context = '{"\u0000*\u0000region":"eu","plain":{"ref":1,"note":"x"},"list":[1,2]}';

        $projected = self::encode($projection->context($context, strlen($context)));

        self::assertStringNotContainsString('invalid_json', $projected);
        self::assertStringContainsString('"\\\\0*\\\\0region":"eu"', $projected);
        self::assertStringContainsString('"plain":{"ref":1,"note":"x"}', $projected);
        self::assertStringContainsString('"list":[1,2]', $projected);

        $payload = '{"uuid":"7f1e2a40-1c3b-4d5e-8f60-718293a4b5c9","job":"X","data":{"\u0000*\u0000hidden":1}}';
        self::assertStringNotContainsString('invalid_json', self::encode($projection->payload($payload, strlen($payload))['payload']));
    }

    /**
     * The summary row of a list (4.3): numbers as numbers, the message
     * redacted, no payload, exception or context.
     *
     * Rejects: decimal strings in a response, a raw message, or a summary
     * that carries the payload.
     */
    public function test_the_summary_row(): void
    {
        $this->push(new CanaryJob(null, true));
        $this->work();
        $columns = JobProjection::SUMMARY_COLUMNS;
        self::assertNotContains('payload', $columns);
        self::assertNotContains('exception', $columns);
        self::assertNotContains('context', $columns);

        $row = $this->mirror()->table(JobStore::TABLE)->first($columns);
        self::assertNotNull($row);
        $summary = $this->projection()->summary($row, ['tags' => ['a'], 'worker' => null]);

        self::assertSame((string) $row->uuid, $summary['id']);
        self::assertSame('failed', $summary['status']);
        self::assertIsFloat($summary['pushed_at']);
        self::assertIsFloat($summary['failed_at']);
        self::assertIsInt($summary['attempts']);
        self::assertIsBool($summary['silenced']);
        self::assertSame(['a'], $summary['tags']);
        self::assertSame(RuntimeException::class, $summary['exception_class']);
        self::assertSame('Login failed password=[redacted] for bob', $summary['exception_message']);
        self::assertArrayNotHasKey('signals', $summary, 'Signals only on stuck rows.');
        self::assertArrayNotHasKey('payload', $summary);
    }

    /**
     * The 2 MB response budget (4.5).
     *
     * Rejects: a response over the budget, or a replacement of a field that
     * fits.
     */
    public function test_the_response_budget(): void
    {
        $projection = $this->projection();
        $response = [
            'id' => 'u',
            'payload' => ['big' => str_repeat('p', 1_200_000)],
            'command' => ['big' => str_repeat('c', 1_100_000)],
            'context' => ['small' => 'c'],
            'exception' => str_repeat('e', 60_000),
            'cycles' => array_fill(0, 51, ['exception_message' => str_repeat('m', 500)]),
        ];

        $fitted = $projection->fit($response);

        self::assertLessThanOrEqual(JobProjection::RESPONSE_BYTES, strlen(self::encode($fitted)));
        self::assertSame('omitted', array_key_first($fitted['payload']));
        self::assertSame('response_budget', $fitted['payload']['omitted']);
        self::assertSame($response['command'], $fitted['command'], 'The largest field goes first; the rest fits.');
        self::assertSame(['small' => 'c'], $fitted['context']);
        self::assertSame($response['exception'], $fitted['exception']);
        self::assertSame($response['cycles'], $fitted['cycles']);
        self::assertSame(['id' => 'u'], $projection->fit(['id' => 'u']));
    }

    /**
     * A redactor that throws (S-3).
     *
     * Rejects: an exception that reaches the host's exception handler with
     * the job value in its message or trace, or a field that keeps the
     * unredacted value.
     */
    public function test_a_throwing_redactor_fails_closed_and_reports_no_job_value(): void
    {
        $reported = $this->spyOnReports();
        $this->app->instance(Redactor::class, new class extends Redactor
        {
            public function tree(mixed $value): mixed
            {
                throw new RuntimeException('canary-redactor-error '.json_encode($value));
            }

            public function text(string $text): string
            {
                throw new RuntimeException('canary-redactor-error '.$text);
            }
        });

        $row = $this->push(new CanaryJob);
        $projected = $this->projection()->payload($row->payload, (int) $row->payload_bytes);

        self::assertSame('[redaction failed]', $projected['payload']);
        self::assertSame('[redaction failed]', $projected['command']);
        self::assertSame('[redaction failed]', $this->projection()->message('Login password=canary-msg'));
        self::assertSame('[redaction failed]', $this->projection()->exception('Bearer canary-exc', 17));
        self::assertNotSame([], $reported->errors, 'The failure is reported.');

        foreach ($reported->errors as $error) {
            self::assertInstanceOf(WatchedOperationFailed::class, $error);
            self::assertStringNotContainsString('canary', $error->getMessage());
            self::assertStringNotContainsString('canary', $error->getTraceAsString());
            self::assertNull($error->getPrevious());
        }
    }

    /**
     * A parse step that throws.
     *
     * Rejects: an unparsed command shown, or the parse error reaching the
     * host.
     */
    public function test_a_throwing_parser_gives_an_error_marker(): void
    {
        $reported = $this->spyOnReports();
        $this->app->instance(SerializedValueParser::class, new class extends SerializedValueParser
        {
            public function command(mixed $command, ProjectionBudget $budget): mixed
            {
                throw new RuntimeException('canary-parser-error '.json_encode($command));
            }
        });

        $row = $this->push(new CanaryJob);
        $projected = $this->projection()->payload($row->payload, (int) $row->payload_bytes);

        self::assertSame(['unavailable' => 'error'], $projected['command']);
        self::assertSame(['unavailable' => 'error'], self::plain($projected['payload'])['data']['command']);

        foreach ($reported->errors as $error) {
            self::assertInstanceOf(WatchedOperationFailed::class, $error);
            self::assertStringNotContainsString('canary', $error->getMessage());
        }
    }

    /**
     * Rejects: job data returned while a configured pattern is invalid.
     */
    public function test_an_invalid_pattern_refuses_every_projection(): void
    {
        $this->app['config']->set('orrery.redact.patterns', ['broken(']);
        $projection = $this->projection();

        foreach ([
            fn () => $projection->payload('{}', 2),
            fn () => $projection->message('m'),
            fn () => $projection->exception('e', 1),
            fn () => $projection->context('{}', 2),
            fn () => $projection->serialized('N;'),
            fn () => $projection->text('t'),
            fn () => $projection->summary((object) ['uuid' => 'u']),
        ] as $index => $read) {
            try {
                $read();
                self::fail("Read {$index} must refuse.");
            } catch (RedactionConfigInvalid $error) {
                self::assertSame('orrery.redact.patterns.0', $error->field);
            }
        }
    }

    /**
     * The stored payload is never changed, and a retry publishes it.
     *
     * Rejects: a projection that writes its redacted form back.
     */
    public function test_the_stored_payload_is_unchanged(): void
    {
        $row = $this->push(new CanaryJob);
        $this->projection()->payload($row->payload, (int) $row->payload_bytes);

        self::assertSame($row->payload, $this->mirror()->table(JobStore::TABLE)->where('uuid', $row->uuid)->value('payload'));
    }

    private function projection(): JobProjection
    {
        return $this->app->make(JobProjection::class);
    }

    /**
     * Pushes a job onto the queue connection `database` and reads its
     * mirror row.
     */
    private function push(object $job): \stdClass
    {
        $this->app['queue']->connection('database')->push($job);
        $row = $this->mirror()->table(JobStore::TABLE)->orderByDesc('id')->first(['uuid', 'payload', 'payload_bytes']);
        self::assertNotNull($row, 'The push wrote its mirror row.');

        return $row;
    }

    /**
     * @return object{errors: list<Throwable>}
     */
    private function spyOnReports(): object
    {
        $spy = new class
        {
            /** @var list<Throwable> */
            public array $errors = [];
        };
        $handler = $this->app->make(ExceptionHandler::class);
        $this->app->instance(ExceptionHandler::class, new class($handler, $spy) implements ExceptionHandler
        {
            public function __construct(private ExceptionHandler $inner, private object $spy) {}

            public function report(Throwable $e)
            {
                $this->spy->errors[] = $e;
            }

            public function shouldReport(Throwable $e)
            {
                return true;
            }

            public function render($request, Throwable $e)
            {
                return $this->inner->render($request, $e);
            }

            public function renderForConsole($output, Throwable $e)
            {
                $this->inner->renderForConsole($output, $e);
            }
        });

        return $spy;
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function plain(mixed $value): mixed
    {
        return json_decode(self::encode($value), true, 512, JSON_THROW_ON_ERROR);
    }
}
