<?php

namespace Justrau\Orrery\Runtime;

use RuntimeException;

/**
 * The error of a WatchResult whose work the open breaker skipped.
 */
final class BreakerOpen extends RuntimeException {}
