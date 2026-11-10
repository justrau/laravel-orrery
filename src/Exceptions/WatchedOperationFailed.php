<?php

namespace Justrau\Orrery\Exceptions;

use Exception;
use Justrau\Orrery\Runtime\SafeError;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * The exception that the watcher reports for a failed watched operation,
 * in place of the original error.
 *
 * - Its message is SafeError::summary(): an allowlist, so it carries no job
 *   value from the SQL bindings or the driver text.
 * - It has no previous exception, and its trace is empty: with
 *   zend.exception_ignore_args off, the frames of an exception built inside
 *   report() carry the host's arguments, for example the job and its
 *   payload. Its file and line are those of the watcher's report call.
 * - Tests and the host's reportable() callbacks match on its public fields,
 *   not on its message: the operation, the class of the cause
 *   (RuntimeException for a string problem), and the SQLSTATE and driver
 *   code of the first query error in the cause's chain.
 */
final class WatchedOperationFailed extends RuntimeException
{
    /**
     * @param  class-string  $causeClass
     */
    private function __construct(
        string $message,
        public readonly string $operation,
        public readonly string $causeClass,
        public readonly ?string $sqlState,
        public readonly int|string|null $driverCode,
    ) {
        parent::__construct($message);
    }

    public static function of(string $operation, Throwable|string $problem, string $file, int $line): self
    {
        $cause = SafeError::cause($problem);
        $error = new self(SafeError::summary($operation, $problem), $operation, $cause['class'], $cause['sqlState'], $cause['driverCode']);

        (new ReflectionProperty(Exception::class, 'trace'))->setValue($error, []);
        $error->file = $file;
        $error->line = $line;

        return $error;
    }
}
