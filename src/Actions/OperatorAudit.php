<?php

namespace Justrau\Orrery\Actions;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Justrau\Orrery\Events\OperatorActionPerformed;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The audit of an operator action on the application's queue tables: it
 * dispatches Events\OperatorActionPerformed and writes one `info` log line
 * with the same facts. Neither runs inside Watcher::run(): an open mirror
 * breaker (local or shared) skips watched work, and the host write has
 * happened, so its audit must not be skipped. A listener or a logger that
 * throws is reported through Watcher::report() and never changes the
 * action's answer. There is no stored activity log.
 */
final class OperatorAudit
{
    /**
     * @param  array<string, string|null>  $target
     * @param  array<string, int|bool|string|null>  $counts
     */
    public function record(string $action, array $target, ?string $actor, string $result, array $counts = []): OperatorActionPerformed
    {
        $event = new OperatorActionPerformed($action, $target, $actor, Clock::now(), $result, $counts);

        try {
            Container::getInstance()->make(Dispatcher::class)->dispatch($event);
        } catch (Throwable $error) {
            Watcher::instance()->report('audit:event', $error);
        }

        try {
            Container::getInstance()->make(LoggerInterface::class)->info(self::line($event), self::context($event));
        } catch (Throwable $error) {
            Watcher::instance()->report('audit:log', $error);
        }

        return $event;
    }

    /**
     * The log context: the event's facts, with every control character of a
     * string escaped as in the line (a log handler may write the context as
     * it is).
     *
     * @return array<string, mixed>
     */
    public static function context(OperatorActionPerformed $event): array
    {
        $escape = static fn (mixed $value): mixed => is_string($value) ? self::escaped($value) : $value;

        return [
            'action' => self::word($event->action),
            'target' => array_map($escape, $event->target),
            'actor' => $escape($event->actor),
            'result' => self::word($event->result),
            'counts' => array_map($escape, $event->counts),
            'at' => $event->at,
        ];
    }

    /**
     * The log line: `Orrery operator action clear by "ops@example.test" on
     * connection="database" queue="default": done`. Every value from outside
     * (the actor, the names of the target) is a JSON string, with every
     * control character escaped (C0 by JSON, DEL and C1 as `\u00XX`), so a
     * queue name with a line break can never forge another log line. The
     * event keeps the values as they are.
     */
    public static function line(OperatorActionPerformed $event): string
    {
        $target = [];

        foreach ($event->target as $key => $value) {
            $target[] = $key.'='.self::quoted($value);
        }

        return sprintf('Orrery operator action %s by %s on %s: %s', self::word($event->action), self::quoted($event->actor), implode(' ', $target), self::word($event->result));
    }

    /**
     * A value as a JSON string on one line (null as `null`).
     */
    private static function quoted(?string $value): string
    {
        if ($value === null) {
            return 'null';
        }

        $json = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        // JSON leaves DEL and the C1 controls (U+0080 to U+009F) as they are.
        return self::escaped($json);
    }

    /**
     * The text with each control character (C0, DEL, C1) as `\u00XX`, and
     * invalid UTF-8 replaced.
     */
    private static function escaped(string $value): string
    {
        return (string) preg_replace_callback('/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u', static fn (array $match): string => sprintf('\\u%04x', mb_ord($match[0], 'UTF-8')), mb_scrub($value, 'UTF-8'));
    }

    /**
     * An action or result code: the package's own words, kept to
     * letters, digits and `_`.
     */
    private static function word(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9_]/', '?', $value);
    }
}
