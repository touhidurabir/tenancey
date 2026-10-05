<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * The tenant cannot be entered right now; the message says why (already audited).
 */
class ImpersonationRefused extends RuntimeException {}
