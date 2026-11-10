<?php

namespace Justrau\Orrery\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackAttachment;
use Illuminate\Notifications\Messages\SlackMessage as LegacySlackMessage;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Justrau\Orrery\Monitoring\StuckNotifications;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\SafeText;
use Psr\Http\Message\UriInterface;

/**
 * The channels of the stuck-job notifications (StuckJobDetected,
 * StuckJobsSummary): the routes of the long-wait notification, and text
 * that a job value cannot turn into markup.
 *
 * - cut(): a name, queue or connection made safe (SafeText: valid UTF-8,
 *   control characters, line and paragraph separators and format
 *   characters such as bidi controls become spaces) and cut to
 *   NAME_CHARACTERS (a job name can have 512, a Slack section holds 3,000).
 * - Mail: the line escapes the Markdown characters \ ] * _ ` (the
 *   template escapes HTML).
 * - Slack: a webhook URL gets the legacy message, whose attachment text
 *   replaces & < > with entities (so `<!channel>` pings nobody) and whose
 *   send has a timeout of 5 s; any other
 *   route gets the Block Kit message with a plain_text section.
 * - SMS: smsText(), at most 160 GSM characters: printable ASCII without the
 *   characters that GSM counts twice.
 *
 * The class that uses it defines title(), summary() and smsText().
 */
trait StuckText
{
    /** The longest name, queue or connection in the text, in characters. */
    public static function nameCharacters(): int
    {
        return 200;
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
            ->subject(self::cut((string) config('orrery.name')).': '.$this->title())
            ->greeting('A queue needs your attention.')
            ->line(self::markdownText($this->summary()));
    }

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

    /**
     * A value made safe for the text and cut to nameCharacters().
     */
    public static function cut(string $value): string
    {
        $value = (string) preg_replace('/[\x{2028}\x{2029}\p{Cf}]/u', ' ', mb_scrub($value, 'UTF-8'));
        $clean = SafeText::clean($value, PHP_INT_MAX) ?? '';
        $max = self::nameCharacters();

        return mb_strlen($clean) > $max ? mb_substr($clean, 0, $max - 1).'…' : $clean;
    }

    /**
     * $text for a line of a Markdown mail: every HTML and Markdown character
     * as a numeric character reference, in an HtmlString, so Blade does not
     * escape it again. CommonMark reads a character reference
     * as text, never as a link, emphasis or tag, and Laravel's text part
     * decodes the references, so the plain-text mail shows the raw value
     * without the backslashes of a Markdown escape.
     */
    public static function markdownText(string $text): HtmlString
    {
        return new HtmlString((string) preg_replace_callback(
            '/[&<>"\'\\\\`*_{}\[\]()#+\-.!|~]/',
            static fn (array $match): string => '&#'.ord($match[0]).';',
            mb_scrub($text, 'UTF-8'),
        ));
    }

    /**
     * $text for a legacy Slack attachment: & < > as entities.
     */
    public static function slackText(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /**
     * $text as at most 160 GSM characters: printable ASCII; the characters
     * that GSM does not have or counts twice ([ ] { } \ ^ ~ | and every
     * other character) become `?`.
     */
    public static function gsm(string $text): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]|[\[\]{}\\\\^~|`]/u', '?', $text);

        return strlen($ascii) > 160 ? substr($ascii, 0, 159).'.' : $ascii;
    }

    private function webhookMessage(): ?LegacySlackMessage
    {
        if (! class_exists(LegacySlackMessage::class)) {
            return null;
        }

        $content = self::slackText($this->summary());
        $title = $this->title();
        $message = (new LegacySlackMessage)
            ->error()
            ->content('A queue needs your attention.')
            ->attachment(static function (SlackAttachment $attachment) use ($title, $content): void {
                $attachment->title($title)->content($content);
            });

        if (Orrery::$slackChannel !== null) {
            $message->to(Orrery::$slackChannel);
        }

        // A send timeout (Guzzle options of the webhook channel), so a slow webhook holds the tick for at most this.
        return $message->http(['timeout' => StuckNotifications::SEND_TIMEOUT_SECONDS, 'connect_timeout' => StuckNotifications::SEND_TIMEOUT_SECONDS]);
    }

    private function blockKitMessage(?string $channel): ?SlackMessage
    {
        if (! class_exists(SlackMessage::class)) {
            return null;
        }

        $content = $this->summary();
        $message = (new SlackMessage)
            ->text('A queue needs your attention.')
            ->headerBlock($this->title())
            ->sectionBlock(static function (SectionBlock $block) use ($content): void {
                // plain_text: a job value is never read as mrkdwn.
                $block->text($content);
            });

        if ($channel !== null) {
            $message->to($channel);
        }

        return $message;
    }
}
