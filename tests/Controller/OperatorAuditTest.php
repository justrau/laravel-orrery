<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Actions\OperatorAudit;
use Justrau\Orrery\Events\OperatorActionPerformed;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\ProcessState;
use Justrau\Orrery\Runtime\SharedBreaker;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Controller\Fakes\AuthorizationJob;
use Justrau\Orrery\Tests\Feature\Hooks\HooksTestCase;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The audit of the operator actions (Actions\OperatorAudit): the event
 * OperatorActionPerformed and its info log line, also while the mirror
 * breaker is open, and a log context without control characters.
 */
final class OperatorAuditTest extends HooksTestCase
{
    private ?string $directory = null;

    protected function setUp(): void
    {
        parent::setUp();

        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => true);
        Orrery::actorUsing(static fn (): string => 'ops@example.test');
    }

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->directory);
        }

        parent::tearDown();
    }

    /**
     * Rejects: an audit that runs inside Watcher::run(), which an open mirror breaker skips (in this process, or
     * shared from another one), so a delete, a run-now or a batch cancel changes the application's tables without
     * its event and its log line.
     * Still passes: an audit that a breaker skips only for another action (each action is checked).
     */
    #[DataProvider('breakers')]
    public function test_each_action_is_recorded_while_the_breaker_is_open(string $breaker): void
    {
        $pending = $this->pushed(0);
        $delayed = $this->pushed(3600);
        $batch = Bus::batch([new AuthorizationJob])->allowFailures()->onConnection('database')->dispatch()->id;
        $this->openBreaker($breaker === 'shared');
        [$events, $logs] = $this->recordAudit();

        $this->postJson("/orrery/api/jobs/{$delayed}/run-now")->assertOk();
        $this->postJson("/orrery/api/jobs/{$pending}/delete")->assertOk();
        $this->postJson("/orrery/api/batches/{$batch}/cancel")->assertOk();

        self::assertTrue(Watcher::instance()->breakerOpen(), 'the breaker stayed open');
        self::assertSame(['run_now', 'delete', 'cancel_batch'], array_map(static fn (OperatorActionPerformed $event): string => $event->action, $events->getArrayCopy()));
        self::assertSame(['available', 'mirror_failed', 'cancelled'], array_map(static fn (OperatorActionPerformed $event): string => $event->result, $events->getArrayCopy()), 'the delete could not remove its row here');
        self::assertCount(3, $logs);
        self::assertSame(0, DB::table('jobs')->where('available_at', '>', time())->count(), 'the run-now happened');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function breakers(): array
    {
        return ['local breaker' => ['local'], 'shared breaker' => ['shared']];
    }

    /**
     * Rejects: a log context that carries a queue name or an actor as it is: a handler that writes the context
     * (a JSON or a line formatter) would write its line breaks and escape sequences; and an event that carries the
     * escaped text instead of the value.
     */
    public function test_the_log_context_escapes_control_characters(): void
    {
        [$events, $logs] = $this->recordAudit();
        $queue = "a\nb\r\x1b[2K\x7f\u{85}c";

        $this->app->make(OperatorAudit::class)->record('clear', ['connection' => 'database', 'queue' => $queue], "ops\u{9b}x", 'done', ['reason' => "r\n"]);

        $context = $logs[0]->context;
        self::assertSame('a\\u000ab\\u000d\\u001b[2K\\u007f\\u0085c', $context['target']['queue']);
        self::assertSame('ops\\u009bx', $context['actor']);
        self::assertSame('r\\u000a', $context['counts']['reason']);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F\x{80}-\x{9F}]/u', (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        self::assertSame($queue, $events[0]->target['queue'], 'the event keeps the value');
    }

    /**
     * Opens the mirror breaker through a lost connection of the mirror. With $shared, the breaker is written to
     * the shared file, and the next "request" (a new watcher) takes it from there.
     */
    private function openBreaker(bool $shared): void
    {
        if ($shared) {
            $this->directory = sys_get_temp_dir().'/orrery-audit-breaker-'.bin2hex(random_bytes(6));
            mkdir($this->directory, 0700);
            $this->app['config']->set('orrery.observer.shared_breaker', true);
            $this->app['config']->set('orrery.observer.shared_breaker_path', $this->directory);
            $this->newWatcher();
        }

        $name = $this->app->make(PackageSession::class)->mirrorSessionName();
        Watcher::instance()->run('test:lost', static function () use ($name): never {
            throw new QueryException($name, 'select 1', [], new class extends PDOException
            {
                public function __construct()
                {
                    parent::__construct('SQLSTATE[08006]: connection failure');
                    $this->code = '08006';
                    $this->errorInfo = ['08006', 7, 'connection failure'];
                }
            });
        });
        self::assertTrue(Watcher::instance()->breakerOpen());

        if ($shared) {
            self::assertCount(1, glob($this->directory.'/*') ?: [], 'the open breaker wrote its shared file');
            $this->newWatcher();
            self::assertSame('skipped', Watcher::instance()->run('test:probe', static fn (): string => 'ran', 'skipped'), 'the shared breaker holds');
        }
    }

    /** A new watcher with its process state, as the next request of PHP-FPM has. */
    private function newWatcher(): void
    {
        foreach ([Watcher::class, Degradation::class, ProcessState::class, SharedBreaker::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
    }

    private function pushed(int $delay): string
    {
        $queue = Queue::connection('database');
        $id = $delay > 0 ? $queue->later($delay, new BasicJob) : $queue->push(new BasicJob);

        return self::uuidOf((string) DB::table('jobs')->where('id', $id)->value('payload'));
    }

    /**
     * @return array{0: \ArrayObject<int, OperatorActionPerformed>, 1: \ArrayObject<int, MessageLogged>}
     */
    private function recordAudit(): array
    {
        $events = new \ArrayObject;
        $logs = new \ArrayObject;
        Event::listen(OperatorActionPerformed::class, static function (OperatorActionPerformed $event) use ($events): void {
            $events[] = $event;
        });
        Event::listen(MessageLogged::class, static function (MessageLogged $message) use ($logs): void {
            if (str_starts_with($message->message, 'Orrery operator action')) {
                $logs[] = $message;
            }
        });

        return [$events, $logs];
    }
}
