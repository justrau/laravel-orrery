<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use RuntimeException;

/**
 * The test harness refuses to go on: the configuration is invalid, the
 * resolved connections do not match it, the run does not own its database, or
 * a helper was used in a state where it could cause damage.
 *
 * Tests catch this type to tell a deliberate refusal from any other error.
 */
final class HarnessRefusal extends RuntimeException
{
}
