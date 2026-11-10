<?php

namespace Justrau\Orrery\Maintenance;

/**
 * What one tick did: the phases that ran (in order), the ones that failed,
 * the ones that ended after the deadline, and the ones that the runner
 * skipped after the deadline.
 */
final class TickReport
{
    /**
     * @param  list<string>  $ran
     * @param  list<string>  $failed
     * @param  list<string>  $overruns
     * @param  list<string>  $skipped
     */
    public function __construct(
        private readonly array $ran,
        private readonly array $failed,
        private readonly array $overruns,
        private readonly array $skipped,
    ) {}

    /** @return list<string> */
    public function ran(): array
    {
        return $this->ran;
    }

    /** @return list<string> */
    public function failed(): array
    {
        return $this->failed;
    }

    /** @return list<string> */
    public function overruns(): array
    {
        return $this->overruns;
    }

    /** @return list<string> */
    public function skipped(): array
    {
        return $this->skipped;
    }
}
