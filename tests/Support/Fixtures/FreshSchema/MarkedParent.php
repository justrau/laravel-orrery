<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\FreshSchema;

use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\TestCase;

/**
 * A parent class with the marker, for the tests of FreshSchema::marked().
 */
#[FreshSchema]
abstract class MarkedParent extends TestCase {}
