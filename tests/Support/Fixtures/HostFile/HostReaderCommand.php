<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\HostFile;

use Illuminate\Console\Command;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\HostFileAccessNotAllowed;
use Justrau\Orrery\Storage\PackageSession;

/**
 * test:host-reader: a package SELECT on the application file, held during
 * its fetch. It asks PackageSession::for('app') for the automatic session,
 * as every automatic package read does. A refused file (D18) prints
 * {"reader":"refused"}. Otherwise it reads the jobs table through a cursor
 * and reaches DURING_FETCH after the first row, with the statement still
 * open, then tries a write on the same session and prints
 * {"reader":"read","rows":n,"mode":...,"writable":bool}.
 */
class HostReaderCommand extends Command
{
    public const DURING_FETCH = 'host_reader.during_fetch';

    /** @var string */
    protected $signature = 'test:host-reader';

    /** @var string */
    protected $description = 'Hold a package read of the application SQLite file (test fixture).';

    public function handle(PackageSession $sessions): int
    {
        try {
            $session = $sessions->for(HostFileFixture::CONNECTION);
        } catch (HostFileAccessNotAllowed) {
            $this->line((string) json_encode(['reader' => 'refused']));

            return self::SUCCESS;
        }

        $mode = null;

        foreach (PackageSession::sessionsForTesting() as $entry) {
            if ($entry['connection'] === $session) {
                $mode = $entry['mode'];
            }
        }

        $rows = 0;

        foreach ($session->cursor('select id from jobs order by id') as $row) {
            if (++$rows === 1) {
                Hooks::reach(self::DURING_FETCH);
            }
        }

        // A write on the session must fail: the automatic session is read-only (D17, D18).
        try {
            $session->getPdo()->exec('delete from jobs where 0 = 1');
            $writable = true;
        } catch (\PDOException) {
            $writable = false;
        }

        $this->line((string) json_encode(['reader' => 'read', 'rows' => $rows, 'mode' => $mode, 'writable' => $writable]));

        return self::SUCCESS;
    }
}
