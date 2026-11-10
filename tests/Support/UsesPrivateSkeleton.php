<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

/**
 * For a test case that writes into the application: orrery:install publishes
 * the provider, the config and the migrations, and edits
 * bootstrap/providers.php. Each test boots from a private copy of Testbench's
 * skeleton (ApplicationSkeleton), so the shared skeleton under vendor/ never
 * changes, and concurrent suites of one checkout never see the files of each
 * other.
 *
 * Call startPrivateSkeleton() in setUp() before parent::setUp(), and
 * endPrivateSkeleton() in tearDown() after parent::tearDown().
 */
trait UsesPrivateSkeleton
{
    /** The private skeleton copy of the current test, or null outside a test. */
    private static ?string $privateSkeleton = null;

    public static function applicationBasePath()
    {
        return self::$privateSkeleton ?? parent::applicationBasePath();
    }

    protected function startPrivateSkeleton(): void
    {
        // The install and publish steps change the schema of the test
        // database: only a test with #[FreshSchema] may run them.
        FreshSchema::assertMarked($this, 'UsesPrivateSkeleton::startPrivateSkeleton()');

        self::$privateSkeleton = ApplicationSkeleton::copy(parent::applicationBasePath());
    }

    protected function endPrivateSkeleton(): void
    {
        $skeleton = self::$privateSkeleton;
        self::$privateSkeleton = null;

        if ($skeleton !== null) {
            ApplicationSkeleton::remove($skeleton);
        }
    }

    protected static function privateSkeleton(): ?string
    {
        return self::$privateSkeleton;
    }
}
