<?php

namespace Justrau\Orrery\Failed;

/**
 * A failer whose records the package cannot act on: retry
 * and forget are refused with the code and the message, and its failures
 * are shown read-only. No provider method is ever called.
 */
final class ReadOnlyFailedRecords extends FailedRecords
{
    private function __construct(private readonly string $code, private readonly string $text) {}

    public static function providerReadOnly(string $class): self
    {
        return new self(self::PROVIDER_READ_ONLY, "Retry and forget need the database-uuids or null failed-job driver; this application uses {$class}. Its failures are shown read-only.");
    }

    public static function idsMayBeReused(): self
    {
        return new self(self::IDS_MAY_BE_REUSED, 'The failed-job table may reuse ids, so Orrery cannot prove which record belongs to a failure. Its failures are shown read-only.');
    }

    public function kind(): string
    {
        return self::READ_ONLY;
    }

    public function mode(string $queueConnection): ?string
    {
        return null;
    }

    public function refusal(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->text;
    }
}
