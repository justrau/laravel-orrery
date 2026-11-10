<?php

namespace Justrau\Orrery\Tests\Feature;

use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Justrau\Orrery\Events\LongWaitDetected;
use Justrau\Orrery\Notifications\LongWaitDetected as LongWaitDetectedNotification;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Tests\TestCase;

class LongWaitDetectedTest extends TestCase
{
    protected function tearDown(): void
    {
        // TestCase resets the notification routes (Support\ResetsPackageState).
        Cache::flush();
        parent::tearDown();
    }

    public function test_event_dispatches_a_notification_to_the_configured_mail_route(): void
    {
        Notification::fake();
        Orrery::routeMailNotificationsTo('ops@example.test');

        event(new LongWaitDetected('database', 'default', 90));

        Notification::assertSentOnDemand(LongWaitDetectedNotification::class);
    }

    public function test_dedup_lock_suppresses_repeated_events_within_the_window(): void
    {
        Notification::fake();
        Orrery::routeMailNotificationsTo('ops@example.test');

        event(new LongWaitDetected('database', 'default', 90));
        event(new LongWaitDetected('database', 'default', 120));

        Notification::assertSentOnDemandTimes(LongWaitDetectedNotification::class, 1);
    }

    public function test_distinct_signatures_are_not_suppressed_by_each_other(): void
    {
        Notification::fake();
        Orrery::routeMailNotificationsTo('ops@example.test');

        event(new LongWaitDetected('database', 'default', 90));
        event(new LongWaitDetected('database', 'emails', 90));

        Notification::assertSentOnDemandTimes(LongWaitDetectedNotification::class, 2);
    }

    public function test_notification_via_returns_only_channels_that_are_configured(): void
    {
        Orrery::routeMailNotificationsTo('ops@example.test');

        $notification = new LongWaitDetectedNotification('database', 'default', 90);

        $this->assertSame(['mail'], $notification->via(null));
    }

    /**
     * Rejects: long-wait texts that carry the raw queue name: a Markdown link in the mail, a `<!channel>` ping in
     * the legacy Slack text, a bidi control or a non-GSM character in the SMS. The texts escape them as the
     * stuck texts do, and still name the queue, the connection and the wait.
     */
    public function test_the_texts_cannot_carry_markup_from_the_queue_name(): void
    {
        $queue = "[Reset](https://evil.test) <!channel>\u{202E}é";
        $notification = new LongWaitDetectedNotification('database', $queue, 90);

        $message = $notification->toMail(new AnonymousNotifiable);
        // The plain-text part shows the raw queue name, without Markdown escapes.
        $mail = (string) $this->app->make(Markdown::class)->renderText((string) $message->markdown, $message->data());
        self::assertStringContainsString('[Reset](https://evil.test)', $mail);
        self::assertStringNotContainsString('\\[', $mail);
        self::assertStringContainsString('"database" connection has a wait time of 90 seconds', $mail);
        self::assertStringNotContainsString("\u{202E}", $mail);
        self::assertStringNotContainsString('href="https://evil.test', (string) $message->render());

        $webhook = $notification->toSlack((new AnonymousNotifiable)->route('slack', 'https://hooks.slack.test/orrery'));
        self::assertInstanceOf(SlackMessage::class, $webhook);
        $text = (string) $webhook->attachments[0]->content;
        self::assertStringContainsString('&lt;!channel&gt;', $text);
        self::assertStringNotContainsString('<!channel>', $text);

        $sms = $notification->smsText();
        self::assertSame(1, preg_match('/^[\x20-\x5A\x5F\x61-\x7A]*$/', $sms), 'GSM characters only: '.$sms);
        self::assertStringContainsString('wait time of 90 seconds', $sms);
        self::assertStringStartsWith('Orrery: the "', $sms, 'A prefix of GSM basic-set characters, never "?Orrery?".');
        self::assertStringStartsWith('Orrery: the "default" queue on the "database" connection has a wait time of 90 seconds.', (new LongWaitDetectedNotification('database', 'default', 90))->smsText());
        // Long names never cut the wait from the SMS.
        self::assertStringEndsWith('has a wait time of 1234567 seconds.', (new LongWaitDetectedNotification(str_repeat('c', 100), str_repeat('q', 300), 1234567))->smsText());
    }
}
