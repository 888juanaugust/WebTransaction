<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use Exception;

/**
 * This statement row is noise — a repeated header, a totals footer, an
 * opening-balance banner. Not a transaction, and not an error either.
 */
final class SkipRow extends Exception {}
