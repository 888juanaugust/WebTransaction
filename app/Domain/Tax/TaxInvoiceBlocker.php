<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Posting\Contracts\Blocker;
use App\Domain\Posting\Contracts\Postable;
use Illuminate\Database\Eloquent\Model;

/**
 * A sales invoice or down payment whose tax invoice serial (NSFP) is recorded
 * has been reported to the tax office: it cannot change or go until the serial
 * is cleared on the e-Tax screen (which is audited).
 */
final class TaxInvoiceBlocker implements Blocker
{
    public function blocks(Postable|Model $document): ?string
    {
        if (! $document instanceof Model || ! array_key_exists('nsfp', $document->getAttributes()) || blank($document->getAttribute('nsfp'))) {
            return null;
        }

        return __('its tax invoice serial :serial is recorded; clear the serial on the e-Tax Invoice Export screen first.', ['serial' => $document->getAttribute('nsfp')]);
    }
}
