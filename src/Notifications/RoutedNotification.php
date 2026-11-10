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
use Justrau\Orrery\Monitoring\StuckNotifications;
use Justrau\Orrery\Orrery;
use Psr\Http\Message\UriInterface;

/**
 * A notification to the long-wait routes (mail, Slack, SMS) with one
 * sentence (summary()) for every channel. The subclasses give the title,
 * the sentence, the SMS and the tone. The names in the sentence are made
 * safe and cut (StuckText::cut()); each channel then escapes its own
 * markup: Markdown in the mail, & < > in the legacy Slack text, GSM in the
 * SMS, plain_text in Block Kit.
 */
abstract class RoutedNotification extends Notification
{
    use Queueable;

    /** The title of the message (the mail subject after the app name, the Slack header). */
    abstract protected function title(): string;

    /** The sentence of every channel. */
    abstract protected function summary(): string;

    /** The SMS: GSM characters only, at most 160. */
    abstract public function smsText(): string;

    /** The mail subject after the app name. */
    protected function mailSubject(): string
    {
        return $this->title();
    }

    /** True for a good-news message (a recovery): the mail and the legacy Slack message use the success tone. */
    protected function good(): bool
    {
        return false;
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
        $message = (new MailMessage)->subject(config('orrery.name').': '.$this->mailSubject());

        return ($this->good() ? $message->success()->greeting('Good news.') : $message->error()->greeting('Oh no! Something needs your attention.'))
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

    public function toVonage(mixed $notifiable): mixed
    {
        $vonageMessageClass = 'Illuminate\\Notifications\\Messages\\VonageMessage';

        if (! class_exists($vonageMessageClass)) {
            return null;
        }

        return (new $vonageMessageClass)->content($this->smsText());
    }

    private function intro(): string
    {
        return $this->good() ? 'Good news.' : 'Oh no! Something needs your attention.';
    }

    private function webhookMessage(): ?LegacySlackMessage
    {
        if (! class_exists(LegacySlackMessage::class)) {
            return null;
        }

        $content = StuckJobDetected::slackText($this->summary());
        $title = $this->title();
        $message = new LegacySlackMessage;
        ($this->good() ? $message->success() : $message->error())
            ->content($this->intro())
            ->attachment(static function (SlackAttachment $attachment) use ($title, $content): void {
                $attachment->title($title)->content($content);
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
            ->text($this->intro())
            ->headerBlock($this->title())
            ->sectionBlock(static function (SectionBlock $block) use ($content): void {
                $block->text($content);
            });

        if ($channel !== null) {
            $message->to($channel);
        }

        return $message;
    }
}
