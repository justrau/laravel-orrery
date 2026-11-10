<?php

namespace Justrau\Orrery\Database\Schema;

use Illuminate\Database\Connection;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\SiblingFileUnavailable;
use Throwable;

/**
 * The schema version check of `/api/health` `schema` and `orrery:install`:
 * the mirror's marker against
 * PackageSchema::currentVersion(), with the hint of that marker. It reads
 * the marker on each call (no process cache), so a long-lived worker or an
 * Octane server never keeps a stale answer after `migrate`.
 *
 * On a server mirror, by the marker:
 * - a marker table without a row (a database loaded from a schema dump):
 *   PackageSchema::UNMARKED_HINT, which drops nothing, or downgradeHint()
 *   when the dump's migrations rows name a newer upgrade migration;
 * - no marker table, with package tables, or below BASELINE:
 *   PackageSchema::UPGRADE_HINT (the pre-v0.1 path, UPGRADE_STEPS);
 * - none, without any package table: PackageSchema::INSTALL_HINT;
 * - from BASELINE up to below VERSION: PackageSchema::MIGRATE_HINT;
 * - VERSION: no hint;
 * - above VERSION: PackageSchema::downgradeHint() (the code was rolled back
 *   after a `migrate`).
 *
 * The sibling installs and upgrades itself when its session opens, so a
 * sibling that opens is current. A sibling that the package refuses
 * (SiblingFileUnavailable: a schema from before BASELINE, package tables
 * without a marker, a newer marker, or a file that cannot be created) gives
 * `{installed: null, expected, hint: <the refusal's message>}`; its hint is
 * never UPGRADE_STEPS.
 */
final class SchemaStatus
{
    /**
     * The status of the mirror. It throws when the marker cannot be read
     * (the caller decides what an unknown status shows).
     *
     * @return array{installed: int|null, expected: int, hint: string|null}
     */
    public static function read(PackageSession $sessions): array
    {
        $refused = self::siblingRefusal($sessions);

        if ($refused !== null) {
            return $refused;
        }

        $mirror = $sessions->mirror();

        return self::of(PackageSchema::installedVersion($mirror), $sessions->mirrorIsSibling(), $mirror);
    }

    /**
     * The status of a sibling mirror that the package refuses, or null (no
     * sibling, a sibling that opens, or another error, which read() then
     * meets again). It opens the sibling outside the watcher, so an open
     * breaker never hides the refusal.
     *
     * @return array{installed: null, expected: int, hint: string}|null
     */
    public static function siblingRefusal(PackageSession $sessions): ?array
    {
        try {
            if (! $sessions->mirrorIsSibling()) {
                return null;
            }

            $sessions->mirror();
        } catch (SiblingFileUnavailable $refused) {
            return ['installed' => null, 'expected' => PackageSchema::currentVersion(), 'hint' => $refused->getMessage()];
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * The rule of the hint for a marker that was read.
     *
     * @return array{installed: int|null, expected: int, hint: string|null}
     */
    private static function of(?int $installed, bool $sibling, Connection $mirror): array
    {
        $expected = PackageSchema::currentVersion();

        $hint = match (true) {
            $installed === $expected => null,
            $installed !== null && $installed > $expected => PackageSchema::downgradeHint($installed, $expected),
            // A sibling that opened has the marker VERSION; any other answer is the sibling's own refusal.
            $sibling => (new SiblingFileUnavailable((string) $mirror->getDatabaseName(), PackageSchema::PRE_BASELINE_SIBLING))->getMessage(),
            $installed === null => match (true) {
                // A marker table without a row: a database loaded from a schema dump, of a
                // newer release when its migrations rows name a newer upgrade migration.
                $mirror->getSchemaBuilder()->hasTable(PackageSchema::MARKER) => self::unmarkedHint($mirror, $expected),
                PackageSchema::hasPackageTables($mirror) => PackageSchema::UPGRADE_HINT,
                default => PackageSchema::INSTALL_HINT,
            },
            $installed < PackageSchema::BASELINE => PackageSchema::UPGRADE_HINT,
            default => PackageSchema::MIGRATE_HINT,
        };

        return ['installed' => $installed, 'expected' => $expected, 'hint' => $hint];
    }

    /**
     * UNMARKED_HINT, or downgradeHint() for a loaded dump of a newer release
     * (PackageSchema::newestRecordedUpgrade(), which adoptUnmarked() refuses).
     */
    private static function unmarkedHint(Connection $mirror, int $expected): string
    {
        $newer = PackageSchema::newestRecordedUpgrade($mirror);

        return $newer !== null && $newer > $expected ? PackageSchema::downgradeHint($newer, $expected) : PackageSchema::UNMARKED_HINT;
    }
}
