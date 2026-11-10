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
     * "Orrery operator action clear by ops@example.test on connection=database queue=default: done".
     */
    public static function line(OperatorActionPerformed $event): string
    {
        $target = [];

        foreach ($event->target as $key => $value) {
            $target[] = $key.'='.($value ?? '-');
        }

        return sprintf('Orrery operator action %s by %s on %s: %s', $event->action, $event->actor ?? 'unknown actor', implode(' ', $target), $event->result);
    }
}
