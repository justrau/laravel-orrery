<?php

namespace Justrau\Orrery\Runtime;

/**
 * The process facts of ProcessContext: process state, a container singleton.
 * The detected context is read once per application.
 */
final class ProcessState
{
    /** The detected context, or null before the first ProcessContext::current(). */
    public ?ProcessContext $current = null;

    /** The context of a test (ProcessContext::useForTesting()), or null. */
    public ?ProcessContext $testing = null;

    /** The time of the first package registration in this process (ProcessContext::markBooted()). */
    public ?float $bootedAt = null;
}
