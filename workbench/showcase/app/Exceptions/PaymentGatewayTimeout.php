<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The failure of the showcase payment job: the gateway did not answer in time.
 */
class PaymentGatewayTimeout extends RuntimeException {}
