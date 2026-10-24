<?php

namespace App\Exceptions;

use RuntimeException;

/** A scanned decision can no longer be confirmed: lapsed, used, not yours, or the pass changed. */
class ScanNotConfirmable extends RuntimeException {}
