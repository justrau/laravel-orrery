<?php

namespace Justrau\Orrery\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackAttachment;
use Illuminate\Notifications\Messages\SlackMessage as LegacySlackMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Str;
use Justrau\Orrery\Contracts\LongWaitDetectedNotification;
use Justrau\Orrery\Monitoring\StuckNotifications;
use Justrau\Orrery\Orrery;
use Psr\Http\Message\UriInterface;

class LongWaitDetected extends Notification implements LongWaitDetectedNotification
{
    use Queueable;

    public string $longWaitConnection;

    public string $longWaitQueue;

    public function __construct(string $connection, string $queue, public int $seconds)
    {
        $this->longWaitConnection = $connection;
        $this->longWaitQueue = $queue;
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return array_values(array_filter([
            Orrery::$slackWebhookUrl !== null ? 'slack' : null,
            Orrery::$smsNumber !== null ? 'vonage' : null,
            Orrery::$email !== null ? 'mail' : null,
        ]));
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject(config('orrery.name').': Long Queue Wait Detected')
            ->greeting('Oh no! Something needs your attention.')
            ->line(StuckJobDetected::markdownText($this->summary()));
    }

    /**
     * Build the Slack message. The route form picks the message class, as in
     * Horizon. laravel/slack-notification-channel sends a webhook URL
     * (http:// or https://) through its webhook channel, which gets the
     * legacy message, with Orrery::$slackChannel as its channel override.
     * Any other route is a channel name or id for the Web API channel, which
     * gets the Block Kit message. Without the channel package this returns
     * null; SendNotification then skips the channel before it sends.
     */
    public function toSlack(mixed $notifiable): mixed
    {
        $route = is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('slack', $this)
            : Orrery::$slackWebhookUrl;

        if ($route instanceof UriInterface || (is_string($route) && Str::startsWith($route, ['http://', 'https://']))) {
            return $this->webhookMessage();
        }

        return $this->blockKitMessage(is_string($route) && $route !== '' ? $route : null);
    }

    private function webhookMessage(): ?LegacySlackMessage
    {
        if (! class_exists(LegacySlackMessage::class)) {
            return null;
        }

        $content = StuckJobDetected::slackText($this->summary());
        $message = (new LegacySlackMessage)
            ->error()
            ->content('Oh no! Something needs your attention.')
            ->attachment(static function (SlackAttachment $attachment) use ($content): void {
                $attachment->title('Long Wait Detected')->content($content);
            });

        if (Orrery::$slackChannel !== null) {
            $message->to(Orrery::$slackChannel);
        }

        // The send timeout of the stuck messages (Guzzle options of the webhook channel), so a slow webhook holds the tick for at most this.
        return $message->http(['timeout' => StuckNotifications::SEND_TIMEOUT_SECONDS, 'connect_timeout' => StuckNotifications::SEND_TIMEOUT_SECONDS]);
    }

    private function blockKitMessage(?string $channel): ?SlackMessage
    {
        if (! class_exists(SlackMessage::class)) {
            return null;
        }

        $content = $this->summary();
        $message = (new SlackMessage)
            ->text('Oh no! Something needs your attention.')
            ->headerBlock('Long Wait Detected')
            ->sectionBlock(static function (SectionBlock $block) use ($content): void {
                $block->text($content);
            });

        if ($channel !== null) {
            $message->to($channel);
        }

        return $message;
    }

    public function toVonage(mixed $notifiable): mixed
    {
        $vonageMessageClass = 'Illuminate\\Notifications\\Messages\\VonageMessage';

        if (! class_exists($vonageMessageClass)) {
            return null;
        }

        return (new $vonageMessageClass)->content($this->smsText());
    }

    /**
     * The SMS: GSM characters only (StuckText::gsm()), at most 160, with the
     * prefix "<name>:" as the stuck-job SMS has it (the brackets of the
     * summary are not GSM basic-set characters). The queue name is cut to
     * 40 characters and the connection name to 20, so the wait always fits.
     */
    public function smsText(): string
    {
        return StuckJobDetected::gsm(sprintf(
            '%s: the "%s" queue on the "%s" connection has a wait time of %s seconds.',
            mb_substr(StuckJobDetected::cut((string) config('orrery.name')), 0, 20),
            mb_substr(StuckJobDetected::cut($this->longWaitQueue), 0, 40),
            mb_substr(StuckJobDetected::cut($this->longWaitConnection), 0, 20),
            $this->seconds,
        ));
    }

    public function signature(): string
    {
        return md5($this->longWaitConnection.$this->longWaitQueue);
    }

    /**
     * The sentence of every channel. The names are made safe and cut
     * (StuckText::cut()); each channel then escapes its own markup, as the
     * stuck texts do: Markdown in the mail, & < > in the legacy Slack text,
     * GSM in the SMS, plain_text in Block Kit.
     */
    protected function summary(): string
    {
        return sprintf(
            '[%s] The "%s" queue on the "%s" connection has a wait time of %s seconds.',
            StuckJobDetected::cut((string) config('orrery.name')),
            StuckJobDetected::cut($this->longWaitQueue),
            StuckJobDetected::cut($this->longWaitConnection),
            $this->seconds,
        );
    }
}
