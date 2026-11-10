<?php

namespace Workbench\App\Exceptions;

use RuntimeException;

/**
 * The exception of the failing sample jobs. The package stores context() with the failed job,
 * so the failed job screen shows a context block.
 */
class WorkbenchJobFailed extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, private readonly array $context = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
