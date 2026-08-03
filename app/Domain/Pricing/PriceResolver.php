<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Money;
use App\Models\Company;
use App\Models\CompanyPriceOverride;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\PriceTierItem;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The one pricing function.
 *
 * Cart, order confirmation, invoice and quote all call resolve(). Price logic
 * lives here and nowhere else — not in the admin panel, not in a report, not
 * in an export. If you find yourself computing a price somewhere else, that is
 * the bug.
 *
 * Resolution order, most specific first:
 *
 *   1. company override for this SKU        (absolute price, then discount)
 *   2. price tier item for this SKU         (absolute price, then discount)
 *   3. company blanket override             (discount off list)
 *   4. price tier blanket discount          (discount off list)
 *   5. published list price
 *
 * Within each of 1–4 the rule with the highest min_qty_base that the ordered
 * quantity actually reaches wins, so quantity breaks work the way staff expect.
 */
class PriceResolver
{
    /**
     * @param  string  $sku  KODE of the product.
     * @param  int  $qtyBase  Ordered quantity in base units.
     * @param  DateTimeInterface|null  $date  Pricing date; defaults to today.
     */
    public function resolve(
        Company $company,
        string $sku,
        int $qtyBase,
        ?DateTimeInterface $date = null,
    ): PriceResolution {
        $date = $date ? Carbon::parse($date)->startOfDay() : Carbon::today();

        $version = PriceListVersion::effectiveOn($date);

        if ($version === null) {
            return PriceResolution::notPriced('no published price list effective on '.$date->toDateString());
        }

        $listItem = PriceListItem::query()
            ->where('version_id', $version->id)
            ->where('kode', $sku)
            ->first();

        if ($listItem === null) {
            return PriceResolution::notPriced("SKU {$sku} is not in price list version {$version->id}", $version->id);
        }

        if (! $listItem->aktif) {
            return PriceResolution::notPriced("SKU {$sku} is not active in version {$version->id}", $version->id);
        }

        $listPrice = $listItem->harga;

        // 1 & 3 — company-specific. SKU-specific beats blanket.
        $override = $this->bestCompanyOverride($company, $sku, $qtyBase, $date);

        if ($override !== null) {
            return $this->fromRule(
                rule: $override,
                listPrice: $listPrice,
                versionId: $version->id,
                priceReason: PriceReason::CompanyOverridePrice,
                discountReason: PriceReason::CompanyOverrideDiscount,
                meta: ['company_price_override_id' => $override->id],
            );
        }

        // 2 & 4 — the tier the company belongs to.
        if ($company->price_tier_id !== null) {
            $tierItem = $this->bestTierItem($company->price_tier_id, $sku, $qtyBase, $date);

            if ($tierItem !== null) {
                return $this->fromRule(
                    rule: $tierItem,
                    listPrice: $listPrice,
                    versionId: $version->id,
                    priceReason: PriceReason::TierItemPrice,
                    discountReason: $tierItem->kode === null
                        ? PriceReason::TierBlanketDiscount
                        : PriceReason::TierItemDiscount,
                    meta: ['price_tier_item_id' => $tierItem->id],
                );
            }

            $tier = $company->priceTier;

            if ($tier !== null && $tier->aktif && $tier->discount_bps > 0) {
                return new PriceResolution(
                    unitPrice: Money::applyDiscountBps($listPrice, $tier->discount_bps),
                    reason: PriceReason::TierBlanketDiscount,
                    priceListVersionId: $version->id,
                    listPrice: $listPrice,
                    discountBps: $tier->discount_bps,
                    meta: ['price_tier_id' => $tier->id, 'price_tier_kode' => $tier->kode],
                );
            }
        }

        // 5 — nothing more specific applies.
        return new PriceResolution(
            unitPrice: $listPrice,
            reason: PriceReason::ListPrice,
            priceListVersionId: $version->id,
            listPrice: $listPrice,
        );
    }

    /**
     * Turn a matched override/tier row into a resolution.
     *
     * A rule carries either an absolute harga or a discount_bps; the absolute
     * price wins if somebody has set both.
     *
     * @param  CompanyPriceOverride|PriceTierItem  $rule
     * @param  array<string, mixed>  $meta
     */
    private function fromRule(
        $rule,
        int $listPrice,
        int $versionId,
        PriceReason $priceReason,
        PriceReason $discountReason,
        array $meta,
    ): PriceResolution {
        $meta['min_qty_base'] = $rule->min_qty_base;

        if ($rule->harga !== null) {
            return new PriceResolution(
                unitPrice: $rule->harga,
                reason: $priceReason,
                priceListVersionId: $versionId,
                listPrice: $listPrice,
                meta: $meta,
            );
        }

        return new PriceResolution(
            unitPrice: Money::applyDiscountBps($listPrice, (int) $rule->discount_bps),
            reason: $discountReason,
            priceListVersionId: $versionId,
            listPrice: $listPrice,
            discountBps: (int) $rule->discount_bps,
            meta: $meta,
        );
    }

    private function bestCompanyOverride(
        Company $company,
        string $sku,
        int $qtyBase,
        Carbon $date,
    ): ?CompanyPriceOverride {
        return CompanyPriceOverride::query()
            ->where('company_id', $company->id)
            ->where(fn ($q) => $q->where('kode', $sku)->orWhereNull('kode'))
            ->where('min_qty_base', '<=', $qtyBase)
            ->tap(fn ($q) => $this->constrainToDate($q, $date))
            // A SKU-specific rule outranks a blanket one at the same quantity.
            ->orderByRaw('CASE WHEN kode IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('min_qty_base')
            ->orderByDesc('id')
            ->first();
    }

    private function bestTierItem(int $tierId, string $sku, int $qtyBase, Carbon $date): ?PriceTierItem
    {
        return PriceTierItem::query()
            ->where('price_tier_id', $tierId)
            ->where(fn ($q) => $q->where('kode', $sku)->orWhereNull('kode'))
            ->where('min_qty_base', '<=', $qtyBase)
            ->tap(fn ($q) => $this->constrainToDate($q, $date))
            ->orderByRaw('CASE WHEN kode IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('min_qty_base')
            ->orderByDesc('id')
            ->first();
    }

    /** NULL bounds mean open-ended in that direction. */
    private function constrainToDate($query, Carbon $date): void
    {
        $query
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date));
    }
}
