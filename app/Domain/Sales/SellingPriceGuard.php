<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Currency\Currencies;
use App\Domain\Currency\CurrencyRates;
use App\Domain\Shared\Format;
use App\Models\Inventory\Item;
use App\Models\Sales\Customer;
use App\Models\Sales\Delivery;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesQuotation;
use App\Models\Sales\SalesReturn;
use App\Modules\ModuleRegistry;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

/**
 * A selling document saved by someone without the "change the selling price" right sells at the price in force:
 * each line at the price the resolver gives (a line pulled from another document at that document's price), no
 * line discount beyond the one in force, no discount on the total beyond the customer's default (or the source
 * document's), the customer's tax terms (prices with or without tax, VAT charged), the book exchange rate, and no
 * charge that takes money off (on a return, none that adds to the credit). The form shows these read-only or
 * filled; this is the check the server makes, whatever the browser sent. Runs after the lines and totals are saved,
 * inside the save's transaction, so a refusal saves nothing.
 */
final class SellingPriceGuard
{
    private const DOCUMENTS = [SalesQuotation::class, SalesOrder::class, Delivery::class, SalesInvoice::class, SalesReturn::class];

    public function __construct(private readonly HakAkses $akses) {}

    public function check(Model $document): void
    {
        $user = auth()->user();
        if (! in_array($document::class, self::DOCUMENTS, true) || $user === null
            || $this->akses->allowsSpecial($user, HakKhusus::ChangeSellingPrice)) {
            return;
        }
        $customer = $document->customer;
        $from = $this->sourceDocument($document);
        $this->checkHeader($document, $customer, $from);

        $rate = (string) ($document->getAttribute('exchange_rate') ?: 1);
        // A foreign price is typed in cents: allow one cent of the document's currency either way.
        $tolerance = BigDecimal::of($rate)->isGreaterThan(1) ? BigDecimal::of($rate)->dividedBy(100, 4, RoundingMode::HalfUp) : BigDecimal::of('0.5');
        foreach ($document->lines()->get() as $line) {
            $item = $line->item_id ? Item::query()->with('units')->find($line->item_id) : null;
            if ($item === null) {
                continue;
            }
            // A pulled line (SourceLineGuard has checked it is the same item and unit) keeps its source's price; a source
            // without a price gives none, so the price in force applies.
            $source = $this->sourceLine($line);
            if ($source !== null && $source->getAttribute('unit_price') !== null) {
                $expected = (string) $source->getAttribute('unit_price');
                $discount = (string) ($source->getAttribute('discount_percent') ?? 0);
            } else {
                $resolved = PriceResolver::resolve($customer, $item, $line->unit_id ? (int) $line->unit_id : null, $document->trans_date, (string) $line->base_quantity);
                [$expected, $discount] = [$resolved['price'], $resolved['discount_percent']];
            }
            if (BigDecimal::of((string) $line->unit_price)->minus($expected)->abs()->isGreaterThan($tolerance)) {
                $this->refuse(__(':item is priced at :price; changing a selling price takes the "change the selling price" right.', ['item' => $item->name, 'price' => Format::price($expected)]));
            }
            // The discount in force at most, whether typed as a percent or slipped in as an amount.
            $gross = BigDecimal::of((string) $line->quantity)->multipliedBy((string) $line->unit_price);
            $allowed = $gross->multipliedBy($discount)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();
            if ((int) $line->discount_amount > $allowed + 1) {
                $this->refuse(__('A discount on :item beyond :percent% takes the "change the selling price" right.', ['item' => $item->name, 'percent' => Format::quantity($discount)]));
            }
        }
    }

    /** The terms on the header: the discount on the total, the tax terms, the exchange rate and the charges. */
    private function checkHeader(Model $document, ?Customer $customer, ?Model $from): void
    {
        $allowed = BigDecimal::max(BigDecimal::of((string) ($customer?->default_sales_disc ?? 0)), BigDecimal::of((string) ($from?->getAttribute('discount_percent') ?? 0)));
        $percent = BigDecimal::of((string) ($document->getAttribute('discount_percent') ?? 0));
        // A percentage within the one in force (its amount follows from it); a fixed amount only with the right.
        if ($percent->isGreaterThan($allowed) || ($percent->isZero() && (int) $document->getAttribute('discount_amount') > 0)) {
            $this->refuse(__('A discount on the total takes the "change the selling price" right.'));
        }

        $inclusive = (bool) ($from?->getAttribute('inclusive_tax') ?? $customer?->default_inc_tax ?? false);
        if ((bool) $document->getAttribute('inclusive_tax') !== $inclusive) {
            $this->refuse(__('Prices with or without tax follow the customer; changing that takes the "change the selling price" right.'));
        }
        if (app(ModuleRegistry::class)->isEnabled('tax') && ! (bool) $document->getAttribute('taxable') && (bool) ($from?->getAttribute('taxable') ?? true)) {
            $this->refuse(__('Leaving out the VAT takes the "change the selling price" right.'));
        }

        $currencyId = $document->getAttribute('currency_id');
        if ($currencyId !== null && Currencies::isForeign((int) $currencyId)) {
            $book = CurrencyRates::on((int) $currencyId, $document->trans_date)['rate'] ?? null;
            $rate = BigDecimal::of((string) ($document->getAttribute('exchange_rate') ?: 0));
            $matches = fn (?string $expected) => $expected !== null && $rate->isEqualTo(BigDecimal::of($expected));
            if ($book !== null && ! $matches($book) && ! $matches($from?->getAttribute('exchange_rate') !== null ? (string) $from->getAttribute('exchange_rate') : null)) {
                $this->refuse(__('The exchange rate is the book rate of the day; another takes the "change the selling price" right.'));
            }
        }

        if (method_exists($document, 'charges')) {
            $return = $document instanceof SalesReturn;
            foreach ($document->charges()->get() as $charge) {
                if ($return ? (int) $charge->amount > 0 : (int) $charge->amount < 0) {
                    $this->refuse(__('A charge that takes money off the customer\'s bill takes the "change the selling price" right.'));
                }
            }
        }
    }

    /** The document the lines were pulled from, if any: its terms are in force for this one. */
    private function sourceDocument(Model $document): ?Model
    {
        $line = $document->lines()->whereNotNull('source_line_id')->first();
        $source = $line !== null ? $this->sourceLine($line) : null;

        return $source !== null && method_exists($source, 'document') ? $source->document() : null;
    }

    private function sourceLine(Model $line): ?Model
    {
        $type = $line->getAttribute('source_line_type');
        $id = $line->getAttribute('source_line_id');
        $class = $type ? Relation::getMorphedModel((string) $type) : null;

        return $class !== null && $id ? $class::query()->find($id) : null;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['data.lines' => $message]);
    }
}
