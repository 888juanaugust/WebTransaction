<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Currency\Currencies;
use App\Domain\Currency\ForeignTotals;
use App\Domain\Fulfilment\StatusDeriver;
use App\Models\Company\Fob;
use App\Models\Company\Shipment;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The common behaviour of a priced document model: recomputing its lines and
 * cached totals from the LineCalculator, and its fulfilment status from its
 * lines. Models declare lines(), and charges() when they have them.
 */
trait PricedDocument
{
    /** A line of a document in the base currency carries no foreign amounts (a document moved back from a foreign currency loses them). */
    private const BASE_ONLY_LINE = ['fc_unit_price' => null, 'fc_discount_amount' => null, 'fc_header_discount' => null, 'fc_amount' => null, 'fc_tax_amount' => null];

    /** Recomputes every line's amounts and the header totals; called after lines are saved, before posting. */
    public function refreshTotal(): void
    {
        $this->refreshPricedTotal();
    }

    public function refreshPricedTotal(): void
    {
        if (Currencies::isForeign($this->getAttribute('currency_id'))) {
            ForeignTotals::refreshPriced($this);
            $this->refreshStatus();

            return;
        }
        $lines = $this->lines()->get();
        $charges = method_exists($this, 'charges') ? $this->charges()->get() : collect();

        $result = LineCalculator::compute(
            $lines->map(fn ($l) => $l->getAttributes())->all(),
            (bool) $this->taxable,
            (bool) $this->inclusive_tax,
            (string) ($this->discount_percent ?? 0),
            // The percentage decides; a fixed amount counts only where no percentage is given.
            BigDecimal::of((string) ($this->discount_percent ?? 0))->isPositive() ? 0 : (int) ($this->discount_amount ?? 0),
            $charges->map(fn ($c) => $c->getAttributes())->all(),
        );

        foreach ($lines as $i => $line) {
            $computed = $result['lines'][$i];
            $line->forceFill([
                'discount_amount' => $computed['discount_amount'],
                'header_discount' => $computed['header_discount'],
                'amount' => $computed['amount'],
                'dpp_amount' => $computed['dpp_amount'],
                'tax_amount' => $computed['tax_amount'],
                ...self::BASE_ONLY_LINE,
            ])->saveQuietly();
        }
        foreach ($charges as $charge) {
            $charge->forceFill(['fc_amount' => null])->saveQuietly();
        }

        $this->forceFill([
            'subtotal' => $result['subtotal'],
            'discount_amount' => $result['discount_amount'],
            'charges_total' => $result['charges_total'],
            'dpp_total' => $result['dpp_total'],
            'tax_total' => $result['tax_total'],
            'total' => $result['total'],
            'exchange_rate' => 1,
            'tax_exchange_rate' => null,
            'fc_subtotal' => null,
            'fc_discount_amount' => null,
            'fc_charges_total' => null,
            'fc_tax_total' => null,
            'fc_total' => null,
        ])->saveQuietly();

        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $this->forceFill(['status' => StatusDeriver::derive($this->lines()->get(), $this->status === StatusDeriver::CLOSED)])->saveQuietly();
    }

    public function isClosed(): bool
    {
        return $this->status === StatusDeriver::CLOSED;
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function fob(): BelongsTo
    {
        return $this->belongsTo(Fob::class);
    }
}
