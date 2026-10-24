<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** A mobile wallet the estate has not set up (or cannot offer); answers 404 with its message. */
class WalletPassUnavailable extends NotFoundHttpException {}
