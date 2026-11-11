<?php

namespace Justrau\Orrery\Registration;

/**
 * A registration module whose listeners and boot() also run while the
 * package is off (Runtime\OffSwitch): it serves only `migrate` and
 * `vendor:publish`, never the queue, a request or a worker.
 */
interface BootsWhileDisabled extends Module {}
