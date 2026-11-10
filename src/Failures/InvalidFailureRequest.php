<?php

namespace Justrau\Orrery\Failures;

use InvalidArgumentException;

/**
 * An invalid scope, key or body of a failure-group request. The controllers
 * answer it with a JSON 422.
 */
final class InvalidFailureRequest extends InvalidArgumentException {}
