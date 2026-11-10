<?php

namespace Justrau\Orrery\Runtime;

use Throwable;

/**
 * The explicit result of watched work. Callers whose
 * success matters (for example the dispatch of a stuck incident, Monitoring\StuckIncidents) read
 * succeeded(), never a null value.
 */
final class WatchResult
{
    private function __construct(
        private readonly bool $succeeded,
        private readonly mixed $value,
        private readonly ?Throwable $error,
    ) {}

    public static function ok(mixed $value): self
    {
        return new self(true, $value, null);
    }

    public static function failed(Throwable $error): self
    {
        return new self(false, null, $error);
    }

    public function succeeded(): bool
    {
        return $this->succeeded;
    }

    /**
     * The work's value; null after a failure.
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * The error of a failure (BreakerOpen when the breaker skipped the work); null after a success.
     */
    public function error(): ?Throwable
    {
        return $this->error;
    }
}
