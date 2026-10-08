<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Company\TaxCode;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/** The tax and branch cells of a payment, receipt or accrual line, and the header's "amounts include tax". */
final class LineTaxFields
{
    /** @return list<TableColumn> */
    public static function columns(): array
    {
        return [TableColumn::make(__('Tax')), TableColumn::make(__('Tax invoice No.')), TableColumn::make(__('Branch'))];
    }

    /**
     * @param  (\Closure(Get): bool)|null  $untaxed  a line that carries no tax of its own (it settles a document)
     * @return list<Component>
     */
    public static function fields(?\Closure $untaxed = null): array
    {
        return [
            Select::make('tax_code_id')->label(__('Tax'))->options(fn () => TaxCode::query()->where('is_active', true)->orderBy('description')->pluck('description', 'id'))->native(false)->placeholder(__('No tax'))
                ->disabled(fn (Get $get) => $untaxed !== null && $untaxed($get)),
            TextInput::make('tax_invoice_number')->label(__('Tax invoice No.'))->maxLength(40)->placeholder(__('Supplier tax invoice'))
                ->disabled(fn (Get $get) => $untaxed !== null && $untaxed($get)),
            BranchFields::select(defaulted: false)->hiddenLabel()->required(false)->placeholder(__('As the document')),
        ];
    }

    public static function inclusiveToggle(): Toggle
    {
        return Toggle::make('inclusive_tax')->label(__('Amounts include tax'))->default(false)->inline(false);
    }
}
