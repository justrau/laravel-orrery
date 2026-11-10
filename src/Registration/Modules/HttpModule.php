<?php

namespace Justrau\Orrery\Registration\Modules;

use Illuminate\Contracts\Foundation\Application;
use Justrau\Orrery\Http\Projection\Redactor;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Registration\Module;
use Justrau\Orrery\Registration\Modules;
use Justrau\Orrery\Tags;

/**
 * The HTTP layer's public static API.
 *
 * It registers the redactor: a process singleton whose
 * compiled rules are cached by a hash of the configuration that it reads
 * at each use. It owns the reset of the public static API, which
 * stays static because the application's provider sets it before any
 * request: the gate callback (Orrery::auth()), the operate callback
 * (Orrery::operateUsing()), the actor resolver (Orrery::actorUsing()), the
 * notification routes and Tags. The test reset hook calls it before and
 * after every test.
 */
final class HttpModule implements Module
{
    /** The process state of this module, resolved in boot() on the base application (Modules::resolveAtBoot()). */
    public const PROCESS_STATE = [
        Redactor::class,
    ];

    public function register(Application $app): void
    {
        $app->singleton(Redactor::class);
    }

    public function boot(Application $app): void
    {
        Modules::resolveAtBoot($app, self::PROCESS_STATE);
    }

    public function listeners(): array
    {
        return [];
    }

    public static function resetForTesting(): void
    {
        Orrery::$authUsing = null;
        Orrery::$operateUsing = null;
        Orrery::$actorUsing = null;
        Orrery::$email = null;
        Orrery::$slackWebhookUrl = null;
        Orrery::$slackChannel = null;
        Orrery::$smsNumber = null;
        Tags::flushState();
    }
}
