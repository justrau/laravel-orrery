<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit;

use Composer\Autoload\ClassLoader;
use Justrau\Orrery\DatabaseQueue;
use Justrau\Orrery\Failed\CapturingUuidFailedJobProvider;
use Justrau\Orrery\Tests\Support\Fixtures\Signatures\ChangedDefaultQueue;
use Justrau\Orrery\Tests\Support\OverrideSignatureAudit;
use Justrau\Orrery\Tests\Support\PackageClasses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The signature audit: every package class that extends a
 * Laravel class keeps the signature of each parent method that it overrides.
 */
final class OverrideSignatureTest extends TestCase
{
    /**
     * The return types that package overrides add where the Laravel parent
     * declares none. Each group is safe:
     * - `DatabaseQueue::pushToDatabase(): mixed`: `mixed` accepts every value, so
     *   it narrows nothing.
     * - `DatabaseConnector::connect(): Queue`: it always returns the package
     *   `DatabaseQueue`, which is a `Queue`, and the queue manager requires one.
     * - The models' `getConnectionName(): ?string`: each returns the package
     *   storage connection name or null, never another type.
     * - The providers' `register(): void`: Laravel calls `register()` and does
     *   not use its return value.
     * A new entry needs a review: a narrowed return type on an override that
     * delegates to its parent can throw a TypeError in the user's queue when
     * the parent returns another type.
     *
     * @var list<string>
     */
    private const ADDED_RETURN_TYPES = [
        'Justrau\\Orrery\\Connectors\\DatabaseConnector::connect(): Illuminate\\Contracts\\Queue\\Queue',
        'Justrau\\Orrery\\DatabaseQueue::pushToDatabase(): mixed',
        'Justrau\\Orrery\\Database\\Models\\FailedTag::getConnectionName(): ?string',
        'Justrau\\Orrery\\Database\\Models\\Job::getConnectionName(): ?string',
        'Justrau\\Orrery\\Database\\Models\\JobTag::getConnectionName(): ?string',
        'Justrau\\Orrery\\Database\\Models\\MonitoredTag::getConnectionName(): ?string',
        'Justrau\\Orrery\\Database\\Models\\Setting::getConnectionName(): ?string',
        'Justrau\\Orrery\\Database\\Models\\Signal::getConnectionName(): ?string',
        'Justrau\\Orrery\\Database\\Models\\WorkerHeartbeat::getConnectionName(): ?string',
        'Justrau\\Orrery\\OrreryApplicationServiceProvider::register(): void',
        'Justrau\\Orrery\\OrreryServiceProvider::register(): void',
    ];

    /**
     * Rejects: discovery from Composer's class map, which is empty for PSR-4 classes on a normal install.
     */
    public function test_inventory_is_complete_on_a_normal_install(): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            $packageEntries = array_filter(
                array_keys($loader->getClassMap()),
                static fn (string $class): bool => str_starts_with($class, PackageClasses::PREFIX),
            );

            self::assertSame([], array_values($packageEntries), "The class map of [{$vendor}] lists package classes, so this run does not prove discovery on an unoptimized install.");
        }

        $inspected = OverrideSignatureAudit::audit(PackageClasses::extendingIlluminate())['inspected'];

        self::assertContains(DatabaseQueue::class, $inspected);
        self::assertContains(CapturingUuidFailedJobProvider::class, $inspected);
    }

    /**
     * Rejects: an override whose default differs from its parent's, for example pop($queue = 'default').
     */
    public function test_every_override_matches_its_parent(): void
    {
        $mismatches = OverrideSignatureAudit::audit(PackageClasses::extendingIlluminate())['mismatches'];

        self::assertSame([], $mismatches, "Override signature mismatches:\n".implode("\n", $mismatches));
    }

    /**
     * Rejects: an audit that compares only method names, parameter counts or optional flags.
     */
    public function test_audit_reports_a_changed_default(): void
    {
        $result = OverrideSignatureAudit::audit([ChangedDefaultQueue::class]);

        self::assertSame([ChangedDefaultQueue::class], $result['inspected']);
        self::assertCount(1, $result['mismatches'], implode("\n", $result['mismatches']));

        $mismatch = $result['mismatches'][0];

        self::assertStringContainsString(ChangedDefaultQueue::class.'::later()', $mismatch);
        self::assertStringContainsString('($queue)', $mismatch);
        self::assertStringContainsString("default 'changed'", $mismatch);
        self::assertStringContainsString('parent default null', $mismatch);
    }

    /**
     * Rejects: an audit or a change that silently narrows the return type of an override.
     */
    public function test_added_return_types_are_the_known_ones(): void
    {
        $added = OverrideSignatureAudit::audit(PackageClasses::extendingIlluminate())['addedReturnTypes'];
        $unknown = array_values(array_diff($added, self::ADDED_RETURN_TYPES));

        self::assertSame([], $unknown, "Return types added by overrides that are not in the reviewed list:\n".implode("\n", $unknown));
    }

    /**
     * Rejects: the old createPayload() override.
     */
    public function test_create_payload_is_not_overridden(): void
    {
        $method = new ReflectionMethod(DatabaseQueue::class, 'createPayload');

        self::assertStringStartsWith('Illuminate\\', $method->getDeclaringClass()->getName());
    }
}
