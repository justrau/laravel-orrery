<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures;

use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use RuntimeException;

/**
 * A tick phase that fails with a data error (a test fixture that
 * TestCommandsProvider tags when ORRERY_TEST_FAILING_PHASE names it).
 */
class FailingTickPhase implements TickPhase
{
    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function priority(): int
    {
        return 0;
    }

    public function run(TickBudget $budget): void
    {
        throw new RuntimeException("the test phase {$this->name} fails");
    }
}
