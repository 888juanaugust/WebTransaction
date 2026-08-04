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
 *
 * ## Reading it once instead of once per SKU
 *
 * Pricing a 20-line order used to run four queries per line — effective
 * version, list item, company overrides, tier items — and the products table
 * ran the same four for every row on screen. A caller that knows its SKUs up
 * front calls prime() first; every resolve() after that is decided from rows
 * already in memory, so the query count stops growing with the batch.
 *
 * prime() is only a hint. resolve() is correct without it — it loads what it
 * needs — so forgetting to prime costs queries, never a wrong price. There is
 * still exactly one place that decides a price.
 *
 * The loaded rows are cached on the instance, which is bound `scoped` — one
 * resolver per HTTP request and per queue job, discarded after. That is also
 * the more correct behaviour: a price list published halfway through
 * confirming an order can no longer price the first half of it at the old
 * version and the second half at the new one.
 */
class PriceResolver
{
    /** @var array<string, PriceListVersion|null> keyed by date */
    private array $versionByDate = [];

    /** @var array<string, PriceListItem|null> keyed "versionId|sku" */
    private array $listItems = [];

    /** @var array<string, list<CompanyPriceOverride>> keyed "companyId|date|sku", sku "*" holding blanket rules */
    private array $companyRules = [];

    /** @var array<string, list<PriceTierItem>> keyed "tierId|date|sku", sku "*" holding blanket rules */
    private array $tierRules = [];

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
        $date = $this->pricingDate($date);

        $this->load($company, [$sku], $date);

        return $this->decide($company, $sku, $qtyBase, $date);
    }

    /**
     * Pre-load the rows a run of resolve() calls will need, so a caller that
     * knows its SKUs up front — an order's lines, a page of the catalogue —
     * pays for one round of queries instead of one round per SKU.
     *
     * Purely an optimisation: resolve() returns the same price either way.
     *
     * @param  list<string>  $skus
     */
    public function prime(Company $company, array $skus, ?DateTimeInterface $date = null): void
    {
        $this->load($company, array_map(strval(...), $skus), $this->pricingDate($date));
    }

    /**
     * Drop everything loaded so far.
     *
     * Publishing a price list is the one thing that can invalidate this cache
     * mid-request, so the importer calls it after a new version goes live.
     */
    public function forget(): void
    {
        $this->versionByDate = [];
        $this->listItems = [];
        $this->companyRules = [];
        $this->tierRules = [];
    }

    private function pricingDate(?DateTimeInterface $date): Carbon
    {
        return $date ? Carbon::parse($date)->startOfDay() : Carbon::today();
    }

    /**
     * Everything a resolution needs, in a bounded number of queries.
     *
     * @param  list<string>  $skus
     */
    private function load(Company $company, array $skus, Carbon $date): void
    {
        $skus = array_values(array_unique($skus));
        $version = $this->versionFor($date);

        if ($version === null) {
            return;
        }

        $this->loadListItems($version, $skus);
        $this->loadCompanyRules($company, $skus, $date);

        if ($company->price_tier_id !== null) {
            $this->loadTierRules($company->price_tier_id, $skus, $date);

            // The blanket tier discount reads this. Eloquent would memoise it
            // on the Company after the first SKU, but that first SKU would
            // then be the only one paying for it — and a caller that primed
            // expects priming to have covered everything.
            $company->loadMissing('priceTier');
        }
    }

    /** Decide one SKU from rows already in memory. */
    private function decide(Company $company, string $sku, int $qtyBase, Carbon $date): PriceResolution
    {
        $version = $this->versionFor($date);

        if ($version === null) {
            return PriceResolution::notPriced('no published price list effective on '.$date->toDateString());
        }

        $listItem = $this->listItems[$version->id.'|'.$sku] ?? null;

        if ($listItem === null) {
            return PriceResolution::notPriced("SKU {$sku} is not in price list version {$version->id}", $version->id);
        }

        if (! $listItem->aktif) {
            return PriceResolution::notPriced("SKU {$sku} is not active in version {$version->id}", $version->id);
        }

        $listPrice = $listItem->harga;

        // 1 & 3 — company-specific. SKU-specific beats blanket.
        $override = $this->best($this->companyRulesFor($company, $sku, $date), $qtyBase);

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
            $tierItem = $this->best($this->tierRulesFor($company->price_tier_id, $sku, $date), $qtyBase);

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

            // Eloquent memoises the relation on the Company instance, so this
            // is one query for the whole batch.
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

    // --- loading ------------------------------------------------------------

    private function versionFor(Carbon $date): ?PriceListVersion
    {
        return $this->versionByDate[$date->toDateString()]
            ??= PriceListVersion::effectiveOn($date);
    }

    /** @param  list<string>  $skus */
    private function loadListItems(PriceListVersion $version, array $skus): void
    {
        $wanted = array_values(array_filter(
            $skus,
            fn (string $sku) => ! array_key_exists($version->id.'|'.$sku, $this->listItems),
        ));

        if ($wanted === []) {
            return;
        }

        // A miss must be remembered as a miss, or every resolve() for an
        // unpriced SKU re-queries for a row that is not there.
        foreach ($wanted as $sku) {
            $this->listItems[$version->id.'|'.$sku] = null;
        }

        PriceListItem::query()
            ->where('version_id', $version->id)
            ->whereIn('kode', $wanted)
            ->get()
            ->each(function (PriceListItem $item) use ($version) {
                $this->listItems[$version->id.'|'.$item->kode] = $item;
            });
    }

    /** @param  list<string>  $skus */
    private function loadCompanyRules(Company $company, array $skus, Carbon $date): void
    {
        $bucket = $this->bucketKey($company->id, $date);

        $wanted = $this->unloaded($this->companyRules, $bucket, $skus);

        if ($wanted === []) {
            return;
        }

        $rows = CompanyPriceOverride::query()
            ->where('company_id', $company->id)
            ->where(fn ($q) => $q->whereIn('kode', $wanted)->orWhereNull('kode'))
            ->tap(fn ($q) => $this->constrainToDate($q, $date))
            ->get()
            ->all();

        $this->file($this->companyRules, $bucket, $wanted, $rows);
    }

    /** @param  list<string>  $skus */
    private function loadTierRules(int $tierId, array $skus, Carbon $date): void
    {
        $bucket = $this->bucketKey($tierId, $date);

        $wanted = $this->unloaded($this->tierRules, $bucket, $skus);

        if ($wanted === []) {
            return;
        }

        $rows = PriceTierItem::query()
            ->where('price_tier_id', $tierId)
            ->where(fn ($q) => $q->whereIn('kode', $wanted)->orWhereNull('kode'))
            ->tap(fn ($q) => $this->constrainToDate($q, $date))
            ->get()
            ->all();

        $this->file($this->tierRules, $bucket, $wanted, $rows);
    }

    /**
     * Which of these SKUs have not been fetched into this bucket yet. The
     * blanket rules ("*") ride along with every fetch, so a cached SKU implies
     * cached blanket rules.
     *
     * @param  array<string, list<object>>  $cache
     * @param  list<string>  $skus
     * @return list<string>
     */
    private function unloaded(array $cache, string $bucket, array $skus): array
    {
        return array_values(array_filter(
            $skus,
            fn (string $sku) => ! array_key_exists($bucket.'|'.$sku, $cache),
        ));
    }

    /**
     * Sort the fetched rows the way the database used to, then split them by
     * SKU. Sorting once globally is safe: none of the sort keys depend on the
     * SKU being asked about, so the order within any subset is unchanged.
     *
     * @param  array<string, list<object>>  $cache
     * @param  list<string>  $skus
     * @param  list<object>  $rows
     */
    private function file(array &$cache, string $bucket, array $skus, array $rows): void
    {
        usort($rows, fn ($a, $b) => [
            $a->kode === null ? 1 : 0, -$a->min_qty_base, -$a->id,
        ] <=> [
            $b->kode === null ? 1 : 0, -$b->min_qty_base, -$b->id,
        ]);

        $blanket = [];

        foreach ($skus as $sku) {
            $cache[$bucket.'|'.$sku] = [];
        }

        foreach ($rows as $row) {
            if ($row->kode === null) {
                $blanket[] = $row;

                continue;
            }

            $cache[$bucket.'|'.$row->kode][] = $row;
        }

        $cache[$bucket.'|*'] = $blanket;
    }

    /**
     * The candidate rules for one SKU, most specific first: its own rules, then
     * the blanket ones. Both lists are already in precedence order.
     *
     * @return list<CompanyPriceOverride>
     */
    private function companyRulesFor(Company $company, string $sku, Carbon $date): array
    {
        $bucket = $this->bucketKey($company->id, $date);

        return array_merge(
            $this->companyRules[$bucket.'|'.$sku] ?? [],
            $this->companyRules[$bucket.'|*'] ?? [],
        );
    }

    /** @return list<PriceTierItem> */
    private function tierRulesFor(int $tierId, string $sku, Carbon $date): array
    {
        $bucket = $this->bucketKey($tierId, $date);

        return array_merge(
            $this->tierRules[$bucket.'|'.$sku] ?? [],
            $this->tierRules[$bucket.'|*'] ?? [],
        );
    }

    /**
     * The highest min_qty_base the order actually reaches. The list is already
     * in precedence order, so this is the first rule the quantity qualifies for.
     *
     * @template T of CompanyPriceOverride|PriceTierItem
     *
     * @param  list<T>  $rules
     * @return T|null
     */
    private function best(array $rules, int $qtyBase)
    {
        foreach ($rules as $rule) {
            if ($rule->min_qty_base <= $qtyBase) {
                return $rule;
            }
        }

        return null;
    }

    private function bucketKey(?int $ownerId, Carbon $date): string
    {
        return ($ownerId ?? 'none').'|'.$date->toDateString();
    }

    /** NULL bounds mean open-ended in that direction. */
    private function constrainToDate($query, Carbon $date): void
    {
        $query
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date));
    }
}
