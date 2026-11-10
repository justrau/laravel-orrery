<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Notifications;

use ArrayObject;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Notifications\SlackChannelServiceProvider;
use Illuminate\Support\Facades\Http;
use Justrau\Orrery\Events\LongWaitDetected;
use Justrau\Orrery\Notifications\LongWaitDetected as LongWaitDetectedNotification;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Tests\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Process;

/**
 * The Slack route: the route form picks the
 * message class. A webhook URL gets the legacy message (with the optional
 * channel override); any other route is a Web API channel and gets the
 * Block Kit message. A null or empty route turns its channel off.
 */
final class SlackRouteTest extends TestCase
{
    private const WEBHOOK = 'https://hooks.slack.test/services/orrery';

    /**
     * @var ArrayObject<int, array{request: RequestInterface}>
     */
    private ArrayObject $webhookRequests;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SlackChannelServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('orrery.name', 'Orrery Test');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The webhook channel posts through Guzzle. The mock answers each POST, and the
        // history keeps the requests.
        $this->webhookRequests = new ArrayObject;
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], 'ok'), new Response(200, [], 'ok')]));
        $requests = $this->webhookRequests;
        $stack->push(Middleware::mapRequest(static function (RequestInterface $request) use ($requests): RequestInterface {
            $requests[] = ['request' => $request];

            return $request;
        }));
        $this->app->instance(SlackWebhookChannel::class, new SlackWebhookChannel(new Client(['handler' => $stack])));
    }

    /**
     * Rejects: the Block Kit message on a webhook route.
     */
    public function test_webhook_route_posts_the_legacy_message(): void
    {
        Http::fake();
        Orrery::routeSlackNotificationsTo(self::WEBHOOK);

        event(new LongWaitDetected('database', 'default', 90));

        self::assertArrayNotHasKey('notification:slack', Degradation::record()['errors'] ?? [], 'the send succeeded');
        self::assertCount(1, $this->webhookRequests, 'one POST to the webhook');
        $request = $this->webhookRequests[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::WEBHOOK, (string) $request->getUri());

        $body = $this->postedJson($request);
        self::assertArrayNotHasKey('blocks', $body, 'no Block Kit payload');
        self::assertSame('Oh no! Something needs your attention.', $body['text']);
        self::assertSame('Long Wait Detected', $body['attachments'][0]['title']);
        self::assertSame(
            '[Orrery Test] The "default" queue on the "database" connection has a wait time of 90 seconds.',
            $body['attachments'][0]['text'],
        );
        self::assertArrayNotHasKey('channel', $body, 'no channel override without a channel');
        Http::assertNothingSent();
    }

    /**
     * Rejects: a stored and unused `$channel`.
     */
    public function test_webhook_route_sends_the_channel_override(): void
    {
        Orrery::routeSlackNotificationsTo(self::WEBHOOK, '#ops');

        event(new LongWaitDetected('database', 'default', 90));

        self::assertCount(1, $this->webhookRequests);
        self::assertSame('#ops', $this->postedJson($this->webhookRequests[0]['request'])['channel'] ?? null, 'the posted JSON names the channel');
    }

    /**
     * Rejects: the legacy message to the Web API channel.
     */
    public function test_channel_route_sends_block_kit_to_that_channel(): void
    {
        $this->app['config']->set('services.slack.notifications.bot_user_oauth_token', 'xoxb-orrery-test');
        Http::fake(['https://slack.com/api/*' => Http::response(['ok' => true])]);
        Orrery::routeSlackNotificationsTo('C0123');

        event(new LongWaitDetected('database', 'default', 90));

        self::assertArrayNotHasKey('notification:slack', Degradation::record()['errors'] ?? [], 'the send succeeded: '.json_encode(Degradation::record()['errors'] ?? []));
        self::assertCount(0, $this->webhookRequests, 'nothing goes to the webhook channel');
        Http::assertSentCount(1);
        Http::assertSent(static function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://slack.com/api/chat.postMessage'
                && ($data['channel'] ?? null) === 'C0123'
                && isset($data['blocks'])
                && str_contains((string) json_encode($data['blocks']), 'The \"default\" queue on the \"database\" connection has a wait time of 90 seconds.');
        });
    }

    /**
     * Rejects: the TypeError of the probe (a `string` parameter that gets the
     * null of `env()` after `config:cache`), and an empty string kept as a route.
     */
    public function test_null_and_empty_routes_disable_the_channel(): void
    {
        $notification = new LongWaitDetectedNotification('database', 'default', 90);
        $notifiable = new AnonymousNotifiable;
        $cases = [
            'slack' => static fn (?string $route) => Orrery::routeSlackNotificationsTo($route, $route),
            'mail' => static fn (?string $route) => Orrery::routeMailNotificationsTo($route),
            'vonage (sms)' => static fn (?string $route) => Orrery::routeSmsNotificationsTo($route),
            'vonage' => static fn (?string $route) => Orrery::routeVonageNotificationsTo($route),
        ];

        foreach ($cases as $label => $route) {
            $channel = explode(' ', $label)[0];

            foreach ([null, ''] as $off) {
                $route('a-route');
                self::assertContains($channel, $notification->via($notifiable), "{$label}: a route turns the channel on");

                $route($off);
                self::assertNotContains($channel, $notification->via($notifiable), sprintf('%s: %s turns the channel off', $label, var_export($off, true)));
            }
        }

        self::assertNull(Orrery::$slackWebhookUrl);
        self::assertNull(Orrery::$slackChannel, 'an empty channel is stored as null');
        self::assertNull(Orrery::$email);
        self::assertNull(Orrery::$smsNumber);

        // The stub example under config:cache: the value is null, and nothing throws.
        Orrery::routeSlackNotificationsTo(config('services.slack.notifications.orrery_webhook_url'));
        self::assertSame([], $notification->via($notifiable));
    }

    /**
     * Rejects: the current stub (no `use Justrau\Orrery\Orrery;`, and `env()` in
     * an example that throws when it is uncommented).
     */
    public function test_stub_examples_are_safe_when_uncommented(): void
    {
        $stub = (string) file_get_contents(__DIR__.'/../../../stubs/OrreryServiceProvider.stub');
        $uncommented = (string) preg_replace('~^(\s*)// (Orrery::route)~m', '$1$2', $stub, -1, $count);

        self::assertGreaterThanOrEqual(3, $count, 'the stub has the route examples');
        self::assertStringContainsString("\nuse Justrau\\Orrery\\Orrery;\n", $uncommented);
        self::assertStringNotContainsString('env(', $uncommented);
        self::assertStringContainsString("config('services.slack.notifications.orrery_webhook_url')", $uncommented);

        $file = sys_get_temp_dir().'/orrery-stub-'.bin2hex(random_bytes(6)).'.php';

        try {
            file_put_contents($file, $uncommented);
            $lint = new Process([PHP_BINARY, '-l', $file]);
            $lint->run();
            self::assertSame(0, $lint->getExitCode(), 'php -l: '.$lint->getOutput().$lint->getErrorOutput());
        } finally {
            @unlink($file);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function postedJson(RequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
