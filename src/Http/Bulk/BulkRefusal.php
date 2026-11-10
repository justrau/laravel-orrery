<?php

namespace Justrau\Orrery\Http\Bulk;

use RuntimeException;

/**
 * A bulk or per-job write request that the server refuses before any work:
 * an HTTP status, a stable code (`error`), a
 * sentence for people (`message`), and the extra fields of the body (for
 * example `field` or `maximum`). The message never holds a job value.
 */
final class BulkRefusal extends RuntimeException
{
    /**
     * @param  array<string, int|string>  $extra
     */
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $field, string $message): self
    {
        return new self(BulkErrors::INVALID, $message, 422, ['field' => $field]);
    }

    /**
     * @return array<string, int|string>
     */
    public function body(): array
    {
        return ['error' => $this->error, 'message' => $this->getMessage()] + $this->extra;
    }
}
