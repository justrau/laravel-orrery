<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller\Fakes;

/**
 * A batch callback that PendingBatch stores as it is (not as a
 * SerializableClosure): an invokable object with a secret property. A
 * batch response must show it only as {"closure": true}.
 */
final class InvokableCanary
{
    public function __construct(public string $note)
    {
    }

    public function __invoke(): void
    {
    }

    public function handle(): void
    {
    }
}
