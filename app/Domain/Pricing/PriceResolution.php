<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use RuntimeException;

/**
 * The result of resolvePrice(): a price, and the reason it resolved that way.
 */
final readonly class PriceResolution
{
    /**
     * @param  int|null  $unitPrice  Whole rupiah per base unit, or null when unpriced.
     * @param  int|null  $listPrice  The published list price this was derived from.
     * @param  array<string, mixed>  $meta  Which rule matched, for the audit trail.
     */
    public function __construct(
        public ?int $unitPrice,
        public PriceReason $reason,
        public ?int $priceListVersionId = null,
        public ?int $listPrice = null,
        public ?int $discountBps = null,
        public array $meta = [],
    ) {}

    public static function notPriced(string $why, ?int $versionId = null): self
    {
        return new self(
            unitPrice: null,
            reason: PriceReason::NotPriced,
            priceListVersionId: $versionId,
            meta: ['why' => $why],
        );
    }

    public function isPriced(): bool
    {
        return $this->unitPrice !== null;
    }

    /**
     * The unit price, refusing to guess when there isn't one.
     *
     * Callers that must have a number (order confirmation, invoicing) use this
     * so an unpriced SKU fails loudly instead of silently becoming zero.
     */
    public function requireUnitPrice(): int
    {
        if ($this->unitPrice === null) {
            throw new RuntimeException(
                'Tidak ada harga berlaku: '.($this->meta['why'] ?? 'unknown')
            );
        }

        return $this->unitPrice;
    }

    /** Line total for a quantity, in base units. */
    public function lineTotal(int $qtyBase): int
    {
        return $this->requireUnitPrice() * $qtyBase;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'unit_price' => $this->unitPrice,
            'reason' => $this->reason->value,
            'price_list_version_id' => $this->priceListVersionId,
            'list_price' => $this->listPrice,
            'discount_bps' => $this->discountBps,
            'meta' => $this->meta,
        ];
    }
}
