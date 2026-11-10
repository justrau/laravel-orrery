<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The failure of the showcase webhook job: the receiving endpoint answered 410 Gone.
 */
class WebhookEndpointGone extends RuntimeException
{
}
