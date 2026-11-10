<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Workbench\App\Exceptions\WorkbenchJobFailed;

/**
 * A job with the long values: the seeder pushes it onto a queue whose name has
 * 255 characters, its display name has 512 characters, and it fails with a message of 64 KB on
 * one line.
 *
 * A PHP class name of 512 characters needs a file name longer than macOS allows, so the long
 * name is the job's display name (the payload's `displayName`, which the dashboard shows).
 */
class LongValuesJob implements ShouldQueue
{
    use Queueable;

    public const NAME_LENGTH = 512;

    public const QUEUE_LENGTH = 255;

    public const MESSAGE_BYTES = 65536;

    public static function longQueue(): string
    {
        return substr('workbench-long-queue-'.str_repeat('abcdefghij', 26), 0, self::QUEUE_LENGTH);
    }

    public function displayName(): string
    {
        return substr(self::class.'\\'.str_repeat('VeryLongNestedNamespaceSegment\\', 20).'End', 0, self::NAME_LENGTH);
    }

    public static function longMessage(): string
    {
        $prefix = 'Workbench long failure: ';

        return $prefix.substr(str_repeat('the import row has a value that is far too long to show in full ', 1100), 0, self::MESSAGE_BYTES - strlen($prefix));
    }

    public function handle(): void
    {
        throw new WorkbenchJobFailed(self::longMessage());
    }
}
