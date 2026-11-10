<?php

namespace Justrau\Orrery\Registration\Modules;

use Illuminate\Contracts\Foundation\Application;
use Justrau\Orrery\Console\TickCommand;
use Justrau\Orrery\Failed\IdReuseProofs;
use Justrau\Orrery\Failed\OccurrenceSlot;
use Justrau\Orrery\Failed\ProviderRegistrar;
use Justrau\Orrery\Failed\StatementCounters;
use Justrau\Orrery\Registration\Module;
use Justrau\Orrery\Registration\Modules;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\FenceTargets;
use Justrau\Orrery\Retry\ForgetAllRecords;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Retry\OperationResolver;
use Justrau\Orrery\Retry\OperationRunner;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\Phases\OperationRetentionPhase;
use Justrau\Orrery\Retry\Phases\OperationsPhase;
use Justrau\Orrery\Retry\Publication;
use Justrau\Orrery\Retry\RetryFinalizer;
use Justrau\Orrery\Retry\RetryHandler;
use Justrau\Orrery\Retry\RetryPayload;
use Justrau\Orrery\Retry\RetryService;

/**
 * The operations: the ledger, the fence, retry, forget and
 * the operation runner.
 *
 * It registers the verifying extender of `queue.failer` (ProviderRegistrar:
 * the package's CapturingUuidFailedJobProvider for exactly Laravel's
 * database-uuids provider), binds the services, tags the runner's two
 * handlers, and tags the phases `operations` (480) and
 * `operation_retention` (520).
 *
 * Lifetimes: the services, the fence (its 60-second table
 * cache), the operation retention phase (its skipped connections) and the
 * provider's statement counters (StatementCounters) and id proofs
 * (IdReuseProofs) are singletons (process state). The occurrence slot of the
 * id binding is unit-of-work state (a scoped binding).
 */
final class OperationsModule implements Module
{
    /** The process state of this module, resolved in boot() on the base application (Modules::resolveAtBoot()). */
    public const PROCESS_STATE = [
        Fence::class,
        OperationRetentionPhase::class,
        StatementCounters::class,
        IdReuseProofs::class,
    ];

    public function register(Application $app): void
    {
        ProviderRegistrar::register($app);

        $app->singleton(Fence::class);
        $app->singleton(FenceTargets::class);
        $app->singleton(Operations::class);
        $app->singleton(OperationRunner::class);
        $app->singleton(OperationResolver::class);
        $app->scoped(OccurrenceSlot::class);

        $app->singleton(Publication::class);
        $app->singleton(RetryPayload::class);
        $app->singleton(RetryFinalizer::class);
        $app->singleton(RetryHandler::class);
        $app->singleton(RetryService::class);

        $app->singleton(ForgetService::class);
        $app->singleton(ForgetAllRecords::class);

        $app->singleton(StatementCounters::class);
        $app->singleton(IdReuseProofs::class);
        $app->singleton(OperationRetentionPhase::class);

        $app->tag([RetryHandler::class, ForgetService::class], OperationRunner::HANDLERS);
        $app->tag([OperationsPhase::class, OperationRetentionPhase::class], TickCommand::PHASES_TAG);
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
        // Every state is a container binding: a new application has new ones.
    }
}
