<?php

namespace Justrau\Orrery\Actions;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Justrau\Orrery\Events\OperatorActionPerformed;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Psr\Log\LoggerInterface;

/**
 * The audit of an operator action on the application's queue tables: it
 * dispatches Events\OperatorActionPerformed and writes one `info` log line
 * with the same facts. Both run inside the watcher (`audit:event`,
 * `audit:log`): a listener or a logger that throws is reported and never
 * changes the action's answer. There is no stored activity log.
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
        $watcher = Watcher::instance();

        $watcher->run('audit:event', static function () use ($event): void {
            Container::getInstance()->make(Dispatcher::class)->dispatch($event);
        });

        $watcher->run('audit:log', static function () use ($event): void {
            Container::getInstance()->make(LoggerInterface::class)->info(self::line($event), [
                'action' => $event->action,
                'target' => $event->target,
                'actor' => $event->actor,
                'result' => $event->result,
                'counts' => $event->counts,
                'at' => $event->at,
            ]);
        });

        return $event;
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
        return (string) preg_replace_callback('/[\x{7F}-\x{9F}]/u', static fn (array $match): string => sprintf('\\u%04x', mb_ord($match[0], 'UTF-8')), $json);
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
