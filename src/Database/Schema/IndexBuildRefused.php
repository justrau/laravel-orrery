<?php

namespace Justrau\Orrery\Database\Schema;

use RuntimeException;

/**
 * The index build (IndexBuild) refused or could not start; its message is
 * for the operator and names no job value.
 */
final class IndexBuildRefused extends RuntimeException
{
}
