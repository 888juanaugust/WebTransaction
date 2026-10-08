<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Currency\Convert;
use App\Domain\Currency\Currencies;
use App\Domain\Documents\LineCalculator;
use App\Domain\Inventory\Units\UnitConverter;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Models\Company\Employee;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Customer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\HtmlString;

/**
 * The standard's priced-document form, in DESIGN.md's skin: a header
 * (party, date, number), the line grid (item, quantity, unit, price, discount,
 * tax, warehouse), "Other info", "Other charges" and live totals computed by
 * the same LineCalculator the posting uses.
 */
final class PricedDocumentForm
{
    public static function header(Select $party, TransactionType $type, ?string $numberLabel = null, array $extra = []): Section
    {
        return Section::make()
            ->columns(3)
            ->schema([
                $party->columnSpan(1),
                DatePicker::make('trans_date')->label(__('fields.trans_date'))->required()->native(false)->default(today())->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, Get $get) => Currencies::isForeign($get('currency_id')) ? CurrencyFields::fillRates($set, $get, $get('currency_id')) : null),
                NumberFields::make($type, $numberLabel),
                ...$extra,
                ...CurrencyFields::header(),
            ]);
    }

    public static function money(string $name, string $label): TextInput
    {
        return MoneyInput::make($name)->label($label)->default(0);
    }

    /**
     * @param  list<Component>  $before  components shown above the grid (a Pull action, say)
     * @param  bool  $groupItems  offer group items (selling documents only; a group is never bought)
     */
    /** @param  bool  $receipt  a goods receipt: the price the goods came in at is set on the server, never taken from the page */
    public static function linesTab(array $before = [], bool $prices = true, bool $warehouse = true, bool $processed = false, ?Closure $priceResolver = null, bool $salesman = false, ?bool $pricesEditable = null, bool $groupItems = false, bool $receipt = false): Tab
    {
        $seesCost = app(HakAkses::class)->allowsSpecial(auth()->user(), HakKhusus::SeeCost);
        $columns = [TableColumn::make(__('Item'))];
        $columns[] = TableColumn::make(__('Quantity'))->alignment(Alignment::End);
        $columns[] = TableColumn::make(__('Unit'));
        if ($prices) {
            $columns[] = TableColumn::make(__('Unit price'))->alignment(Alignment::End);
            $columns[] = TableColumn::make(__('Disc %'))->alignment(Alignment::End);
            $columns[] = TableColumn::make(__('Amount'))->alignment(Alignment::End);
            $columns[] = TableColumn::make(__('Tax'));
        }
        if ($warehouse) {
            $columns[] = TableColumn::make(__('Warehouse'));
        }
        if ($salesman) {
            $columns[] = TableColumn::make(__('Salesperson'));
        }
        if ($processed) {
            $columns[] = TableColumn::make(__('Processed'))->alignment(Alignment::End);
        }
        array_push($columns, ...TagFields::columns());
        $columns[] = TableColumn::make(__('Memo'));

        $fields = [
            LineItemFields::item(groups: $groupItems)->afterStateUpdated(function (Set $set, Get $get, $state) use ($priceResolver, $receipt): void {
                $item = $state ? Item::query()->find($state) : null;
                $set('unit_id', $item?->unit1_id);
                if ($item && ! $get('source_line_id') && ! $receipt) { // a receipt's price is set on the server
                    self::setPrice($set, $get, $priceResolver ? $priceResolver($item, $get) : (string) $item->purchase_price);
                    $set('tax_code_id', $item->tax1_id ?? TaxCode::default()?->id);
                }
                LineItemFields::syncBase($set, $get);
            })->disabled(fn (Get $get) => filled($get('source_line_id')))->dehydrated(), // a pulled line keeps its item (SourceLineGuard)
            LineItemFields::quantity()->minValue(0.0001)->afterStateUpdated(fn (Set $set, Get $get) => self::reprice($set, $get, $priceResolver))
                ->rules($salesman ? [fn (Get $get): Closure => self::minimumSaleRule($get)] : []),
            LineItemFields::unit()->afterStateUpdated(fn (Set $set, Get $get) => self::reprice($set, $get, $priceResolver))
                ->disabled(fn (Get $get) => filled($get('source_line_id')))->dehydrated(), // and its unit
        ];
        $pricesEditable ??= ! $salesman || app(HakAkses::class)->allowsSpecial(auth()->user(), HakKhusus::ChangeSellingPrice);
        if ($prices) {
            $fields[] = TextInput::make('unit_price')->label(__('Unit price'))->numeric()->default(0)->live(onBlur: true)->prefix(fn (Get $get) => CurrencyFields::symbol($get('../../currency_id')))->readOnly(! $pricesEditable);
            $fields[] = TextInput::make('discount_percent')->label(__('Disc %'))->numeric()->default(0)->minValue(0)->maxValue(100)->live(onBlur: true);
            $fields[] = Placeholder::make('amount_preview')->label(__('Amount'))->hiddenLabel()->content(fn (Get $get) => CurrencyFields::number(self::lineAmount($get), $get('../../currency_id')));
            $fields[] = Select::make('tax_code_id')->label(__('Tax'))->options(fn () => TaxCode::query()->where('is_active', true)->orderBy('description')->pluck('description', 'id'))->native(false)->live();
        }
        if (! $prices) {
            // Price-less grids (receipts) still carry the price the goods came in at, for the ledger.
            $fields[] = Hidden::make('unit_price')->default(0)->dehydrated();
            $fields[] = Hidden::make('discount_percent')->default(0)->dehydrated();
            $fields[] = Hidden::make('tax_code_id')->dehydrated();
        }
        if ($warehouse) {
            $fields[] = Select::make('warehouse_id')->label(__('Warehouse'))->options(fn () => Warehouse::query()->visibleTo(auth()->user())->where('is_system', false)->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->native(false)->required()
                ->default(fn () => Warehouse::default()?->id);
        }
        if ($salesman) {
            $fields[] = Select::make('salesman_id')->label(__('fields.salesman'))->options(fn () => Employee::query()->salesmen()->orderBy('name')->pluck('name', 'id'))->native(false)
                ->default(fn (Get $get) => $get('../../customer_id') ? Customer::query()->find($get('../../customer_id'))?->salesman_id : null);
        }
        if ($processed) {
            $fields[] = TextInput::make('processed_quantity')->label(__('fields.processed_quantity'))->numeric()->disabled()->dehydrated(false)->default(0);
        }
        array_push($fields, ...TagFields::lineFields());
        $fields[] = TextInput::make('memo')->label(__('Memo'))->maxLength(255);
        $fields[] = LineItemFields::baseQuantity();
        $fields[] = Hidden::make('source_line_type')->dehydrated();
        $fields[] = Hidden::make('source_line_id')->dehydrated();
        $fields[] = Hidden::make('discount_amount')->default(0)->dehydrated();

        return Tab::make(__('fields.lines'))->schema([
            ...$before,
            Repeater::make('lines')->label(__('fields.lines'))
                ->hiddenLabel()
                ->relationship()
                ->orderColumn('sort')
                ->table($columns)
                ->schema($fields)
                ->minItems(1)
                ->defaultItems(1)
                ->live()
                ->addActionLabel(__('Add line'))
                ->mutateRelationshipDataBeforeFillUsing(fn (array $data) => $receipt && ! $seesCost ? ['unit_price' => null, 'discount_percent' => null, 'tax_code_id' => null] + $data : self::fillLine($data))
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, Get $get) => self::normaliseLine($receipt ? self::receiptPrice($data) : $data, $get('currency_id'), $get('exchange_rate')))
                ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, Get $get) => self::normaliseLine($receipt ? self::receiptPrice($data) : $data, $get('currency_id'), $get('exchange_rate'))),
            ...($prices ? [self::totals()] : []),
        ]);
    }

    /** An item sold at wholesale prices is priced again when its quantity or unit changes (a pulled line keeps its price). */
    private static function reprice(Set $set, Get $get, ?Closure $priceResolver): void
    {
        if ($priceResolver === null || $get('source_line_id') || ! $get('item_id')) {
            return;
        }
        $item = Item::query()->find($get('item_id'));
        if ($item?->use_wholesale_price) {
            self::setPrice($set, $get, $priceResolver($item, $get));
        }
    }

    /**
     * Puts a resolved price on the line: a bare price, or the sales resolver's price with the discount in force
     * (a discount-type price adjustment, the customer's default discount).
     *
     * @param  string|int|float|array{price: string, discount_percent?: string}|null  $resolved
     */
    private static function setPrice(Set $set, Get $get, string|int|float|array|null $resolved): void
    {
        if (is_array($resolved)) {
            $set('discount_percent', (string) ($resolved['discount_percent'] ?? 0));
            $resolved = $resolved['price'];
        }
        $set('unit_price', self::inDocumentCurrency($resolved, $get));
    }

    /** An item with a minimum sale quantity is not sold below it (compared in base units). */
    private static function minimumSaleRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $item = $get('item_id') ? Item::query()->with('units')->find($get('item_id')) : null;
            if ($item === null || BigDecimal::of((string) $item->min_sell_qty)->isZero() || ! is_numeric($value)) {
                return;
            }
            $base = UnitConverter::toBase($item, (string) $value, (int) ($get('unit_id') ?: $item->unit1_id));
            if (BigDecimal::of($base)->isLessThan((string) $item->min_sell_qty)) {
                $fail(__(':item is sold from :quantity (base unit).', ['item' => $item->name, 'quantity' => Format::quantity((string) $item->min_sell_qty)]));
            }
        };
    }

    /** A price list or item price (base currency) in the document's currency, at the document's rate. */
    private static function inDocumentCurrency(string|int|float|null $basePrice, Get $get): string
    {
        $currencyId = $get('../../currency_id');

        return Currencies::isForeign($currencyId) ? Convert::priceFromBase($basePrice, $get('../../exchange_rate') ?: 1) : (string) ($basePrice ?? 0);
    }

    /** A saved line as the grid shows it: a foreign document's prices in its own currency. */
    /**
     * What goods came in at, on a receipt: the order line's price when the line was pulled from an order (in the
     * order's currency, as typed prices are), else the item's purchase price. The page never sends it.
     */
    private static function receiptPrice(array $data): array
    {
        $class = filled($data['source_line_type'] ?? null) ? Relation::getMorphedModel((string) $data['source_line_type']) : null;
        $source = $class !== null && filled($data['source_line_id'] ?? null) ? $class::query()->find($data['source_line_id']) : null;
        if ($source !== null && $source->getAttribute('unit_price') !== null) {
            return ['unit_price' => (string) ($source->getAttribute('fc_unit_price') ?? $source->getAttribute('unit_price')),
                'discount_percent' => (string) ($source->getAttribute('discount_percent') ?? 0), 'discount_amount' => 0, 'tax_code_id' => $source->getAttribute('tax_code_id')] + $data;
        }
        $item = filled($data['item_id'] ?? null) ? Item::query()->find($data['item_id']) : null;

        return ['unit_price' => (string) ($item?->purchase_price ?? 0), 'discount_percent' => 0, 'discount_amount' => 0, 'tax_code_id' => $item?->tax1_id] + $data;
    }

    public static function fillLine(array $data): array
    {
        if (($data['fc_unit_price'] ?? null) !== null) {
            $data['unit_price'] = $data['fc_unit_price'];
        }

        return $data;
    }

    /**
     * Every money column present and numeric, the base quantity in step, before a line is saved. In a foreign
     * document the typed price is in the document's currency: it is kept as fc_unit_price and the base columns
     * follow from it when the document's totals are refreshed.
     */
    public static function normaliseLine(array $data, mixed $currencyId = null, mixed $rate = null): array
    {
        $data = LineItemFields::fillBaseQuantities([$data])[0];
        foreach (['unit_price', 'discount_percent', 'discount_amount', 'amount', 'dpp_amount', 'tax_amount'] as $column) {
            if (! isset($data[$column]) || $data[$column] === '') {
                $data[$column] = 0;
            }
        }
        if (Currencies::isForeign($currencyId)) {
            $data['fc_unit_price'] = (string) $data['unit_price'];
            $data['unit_price'] = Convert::priceToBase($data['unit_price'], $rate ?: 1);
        } else {
            $data['fc_unit_price'] = null;
        }
        foreach (['source_line_type', 'source_line_id', 'tax_code_id', 'warehouse_id', 'unit_id', 'department_id', 'project_id'] as $column) {
            if (isset($data[$column]) && $data[$column] === '') {
                $data[$column] = null;
            }
        }

        return $data;
    }

    /** The line's amount in the document currency's minor units. */
    private static function lineAmount(Get $get): int
    {
        $scale = BigDecimal::ten()->power(CurrencyFields::decimals($get('../../currency_id')));
        $result = LineCalculator::compute([[
            'quantity' => is_numeric($get('quantity')) ? $get('quantity') : 0,
            'unit_price' => (string) BigDecimal::of(is_numeric($get('unit_price')) ? (string) $get('unit_price') : '0')->multipliedBy($scale),
            'discount_percent' => $get('discount_percent') ?: 0,
            'discount_amount' => 0,
            'tax_code_id' => null,
        ]], false, false);

        return $result['lines'][0]['amount'] ?? 0;
    }

    public static function totals(): Placeholder
    {
        return Placeholder::make('totals')->label(__('Total'))
            ->hiddenLabel()
            ->content(function (Get $get): HtmlString {
                $currencyId = $get('currency_id');
                $foreign = Currencies::isForeign($currencyId);
                $decimals = CurrencyFields::decimals($currencyId);
                $scale = BigDecimal::ten()->power($decimals);
                $lines = array_values((array) $get('lines'));
                $charges = array_values((array) $get('charges'));
                if ($foreign) {
                    // In the document's currency, worked in its minor units.
                    $lines = array_map(fn ($l) => ['unit_price' => (string) BigDecimal::of(is_numeric($l['unit_price'] ?? null) ? (string) $l['unit_price'] : '0')->multipliedBy($scale)] + (array) $l, $lines);
                    $charges = array_map(fn ($c) => ['amount' => self::typedMinor($c['amount'] ?? 0, $decimals)], $charges);
                }
                $result = LineCalculator::compute(
                    $lines,
                    (bool) $get('taxable'),
                    (bool) $get('inclusive_tax'),
                    (string) ($get('discount_percent') ?: 0),
                    0,
                    $charges,
                );
                $rows = [
                    [__('fields.subtotal'), $result['subtotal']],
                    [__('fields.discount'), -$result['discount_amount']],
                    [__('fields.charges_total'), $result['charges_total']],
                    [__('fields.dpp_total'), $result['dpp_total']],
                    [__('fields.tax_total'), $result['tax_total']],
                ];
                $html = '<div class="ae-totals">';
                foreach ($rows as [$label, $value]) {
                    if ($value === 0 && ! in_array($label, [__('fields.subtotal'), __('fields.tax_total')], true)) {
                        continue;
                    }
                    $html .= '<div class="ae-totals-row"><span>'.e($label).'</span><span class="ae-money">'.e(CurrencyFields::number($value, $currencyId)).'</span></div>';
                }
                $html .= '<div class="ae-totals-row ae-totals-grand"><span>'.e(__('fields.total')).'</span><span class="ae-money">'.e(CurrencyFields::format($result['total'], $currencyId)).'</span></div>';
                if ($foreign) {
                    $rate = is_numeric($get('exchange_rate')) ? (string) $get('exchange_rate') : '1';
                    $taxRate = is_numeric($get('tax_exchange_rate')) ? (string) $get('tax_exchange_rate') : $rate;
                    $html .= '<div class="ae-totals-row"><span>'.e(__('In :currency at :rate', ['currency' => Format::symbol(), 'rate' => Format::quantity($rate, 8)])).'</span><span class="ae-money">≈ '.e(Format::rupiah(Convert::toBase($result['total'], $rate, $decimals))).'</span></div>';
                    if ($result['tax_total'] !== 0) {
                        $html .= '<div class="ae-totals-row"><span>'.e(__('VAT in :currency at the tax rate :rate', ['currency' => Format::symbol(), 'rate' => Format::quantity($taxRate, 8)])).'</span><span class="ae-money">≈ '.e(Format::rupiah(Convert::toBase($result['tax_total'], $taxRate, $decimals))).'</span></div>';
                    }
                }

                return new HtmlString($html.'</div>');
            });
    }

    /** A typed amount (masked or plain) in minor units; anything unreadable counts as nothing. */
    private static function typedMinor(mixed $typed, int $decimals): int
    {
        try {
            return Convert::minor(is_scalar($typed) ? $typed : 0, $decimals);
        } catch (\InvalidArgumentException) {
            return 0;
        }
    }

    /** @param  list<Component>  $extra */
    public static function otherInfoTab(array $extra = [], bool $shipping = true): Tab
    {
        return Tab::make(__('fields.other_info'))->schema([
            ...$extra,
            BranchFields::select(),
            ...TagFields::header(),
            Textarea::make('to_address')->label(__('Address'))->rows(2),
            Textarea::make('description')->label(__('fields.description'))->rows(2),
            Toggle::make('taxable')->label(__('fields.taxable'))->default(true)->live(),
            Toggle::make('inclusive_tax')->label(__('fields.inclusive_tax'))->default(false)->live(),
            TextInput::make('discount_percent')->label(__('Discount on the total (%)'))->numeric()->minValue(0)->maxValue(100)->default(0)->live(onBlur: true),
            ...($shipping ? [
                DatePicker::make('ship_date')->label(__('fields.ship_date'))->native(false),
                Select::make('shipment_id')->label(__('fields.shipment'))->relationship('shipment', 'name')->preload()->native(false),
                Select::make('fob_id')->label(__('fields.fob'))->relationship('fob', 'name')->preload()->native(false),
            ] : []),
        ])->columns(2);
    }

    public static function chargesTab(bool $allocateToCost = false): Tab
    {
        $columns = [TableColumn::make(__('Charge')), TableColumn::make(__('Amount'))->alignment(Alignment::End), ...TagFields::columns(), TableColumn::make(__('Description'))];
        $fields = [
            Select::make('account_id')->label(__('Account'))->options(fn () => Account::options(AccountType::Expense, AccountType::OtherExpense, AccountType::CostOfSales, AccountType::OtherCurrentAsset, AccountType::OtherIncome))->searchable()->required()->native(false),
            MoneyInput::inCurrency('amount', fn (Get $get) => CurrencyFields::decimals($get('../../currency_id')))->label(__('Amount'))->default(0)->live(onBlur: true),
            ...TagFields::lineFields(),
            TextInput::make('description')->label(__('Description'))->maxLength(255),
        ];
        if ($allocateToCost) {
            $columns[] = TableColumn::make(__('Into item cost'));
            $fields[] = Toggle::make('allocate_to_cost')->default(false);
        }

        return Tab::make(__('fields.other_charges'))->schema([
            Repeater::make('charges')->label(__('fields.other_charges'))
                ->hiddenLabel()
                ->relationship()
                ->orderColumn('sort')
                ->table($columns)
                ->schema($fields)
                ->defaultItems(0)
                ->live()
                ->addActionLabel(__('Add charge'))
                ->mutateRelationshipDataBeforeFillUsing(fn (array $data, Get $get) => CurrencyFields::fromForeign($data, $get('currency_id'), ['amount' => 'fc_amount']))
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, Get $get) => CurrencyFields::toForeign($data, $get('currency_id'), ['amount' => 'fc_amount']))
                ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, Get $get) => CurrencyFields::toForeign($data, $get('currency_id'), ['amount' => 'fc_amount'])),
        ]);
    }

    /** The remaining lines of an upstream document, shaped for the grid, pointing back at their source. */
    public static function pulledLines(iterable $lines, string $sourceLineType, bool $withPrices = true): array
    {
        $out = [];
        foreach ($lines as $line) {
            $remaining = $line->remainingQuantity();
            if (! BigDecimal::of($remaining)->isPositive()) {
                continue;
            }
            $ratio = UnitConverter::ratio($line->item->load('units'), $line->unit_id ?? $line->item->unit1_id);
            $row = [
                'item_id' => $line->item_id,
                'quantity' => (string) BigDecimal::of($remaining)->dividedBy($ratio, 4, RoundingMode::HalfUp),
                'unit_id' => $line->unit_id ?? $line->item->unit1_id,
                'base_quantity' => $remaining,
                'warehouse_id' => $line->warehouse_id ?? null,
                'memo' => $line->memo,
                'source_line_type' => $sourceLineType,
                'source_line_id' => $line->id,
                'department_id' => $line->department_id ?? null,
                'project_id' => $line->project_id ?? null,
            ];
            if (isset($line->salesman_id)) {
                $row['salesman_id'] = $line->salesman_id;
            }
            if ($withPrices) {
                $row['unit_price'] = (string) ($line->fc_unit_price ?? $line->unit_price ?? 0); // pulled only from documents in the same currency
                $row['discount_percent'] = (string) ($line->discount_percent ?? 0);
                $row['tax_code_id'] = $line->tax_code_id ?? null;
            }
            $out[] = $row;
        }

        return $out;
    }
}
