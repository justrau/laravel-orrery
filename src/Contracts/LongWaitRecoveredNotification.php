<?php

namespace Justrau\Orrery\Contracts;

/**
 * The notification of a long-wait recovery (Events\LongWaitRecovered). Bind
 * your own class to it, as for LongWaitDetectedNotification; it is built
 * with the parameters `connection`, `queue` and `seconds` (the duration).
 */
interface LongWaitRecoveredNotification
{
    //
}
