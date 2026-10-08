<?php

declare(strict_types=1);

namespace App\Domain\CashBank\Contracts;

use App\Domain\CashBank\GiroDetails;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/** A document that may carry a giro: a receipt or payment settled by cheque. */
interface GiroSource
{
    /** The giro this document carries, or null when it was settled another way. */
    public function giroDetails(): ?GiroDetails;

    public function giro(): MorphOne;
}
