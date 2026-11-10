<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Attribute;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The opt-out marker of the reuse schema mode (see Support\ReusedSchema).
 *
 * A test that runs DDL or changes the schema carries this attribute on its
 * method, on its class, or on a parent class. It then gets its own fresh
 * migration and rollback in both schema modes, as every test does in the
 * fresh mode. Examples: the schema and fence-migration tests, the install
 * and optional-index tests, every user of a mirror outage (it renames tables
 * or redirects the mirror), and the alt-server tests.
 *
 * MirrorOutage::begin(), UsesAltServer::useAltServer() and
 * UsesPrivateSkeleton::startPrivateSkeleton() refuse to run in a test
 * without the marker, in both modes (required() and assertMarked()). In the
 * reuse mode, the schema fingerprint guard fails every other test that
 * leaves the schema changed.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class FreshSchema
{
    /** The running test without the marker, or null (a marked test, or no test). */
    private static ?string $unmarked = null;

    /**
     * Whether the test method, its class or a parent class carries the marker.
     */
    public static function marked(PHPUnitTestCase $test): bool
    {
        $method = $test->name();

        if (method_exists($test, $method) && (new ReflectionMethod($test, $method))->getAttributes(self::class) !== []) {
            return true;
        }

        for ($class = new ReflectionClass($test); $class !== false; $class = $class->getParentClass()) {
            if ($class->getAttributes(self::class) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Records the running test (TestCase::setUp()).
     */
    public static function enter(PHPUnitTestCase $test): void
    {
        self::$unmarked = self::marked($test) ? null : $test::class.'::'.$test->name();
    }

    /**
     * Forgets the running test (TestCase::tearDown()).
     */
    public static function leave(): void
    {
        self::$unmarked = null;
    }

    /**
     * Refuses a schema-changing helper in a running test without the marker.
     *
     * @throws HarnessRefusal
     */
    public static function required(string $helper): void
    {
        if (self::$unmarked !== null) {
            throw new HarnessRefusal(self::message($helper, self::$unmarked));
        }
    }

    /**
     * Refuses a schema-changing helper in a test without the marker, before
     * the test case's setUp() recorded the test.
     *
     * @throws HarnessRefusal
     */
    public static function assertMarked(PHPUnitTestCase $test, string $helper): void
    {
        if (! self::marked($test)) {
            throw new HarnessRefusal(self::message($helper, $test::class.'::'.$test->name()));
        }
    }

    private static function message(string $helper, string $test): string
    {
        return sprintf(
            '%s changes the schema, so %s needs the attribute #[%s] on its method or class. The marker gives it a fresh migration in both schema modes (ORRERY_TEST_SCHEMA=fresh|reuse).',
            $helper,
            $test,
            self::class,
        );
    }
}
