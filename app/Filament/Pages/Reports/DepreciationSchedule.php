<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Reports\CashAndAssetReports;
use App\Models\FixedAssets\AssetCategory;
use Filament\Forms\Components\Select;

/** Depreciation Schedule (depreciation-schedule): every asset's cost, depreciation and book value at the period's end. */
class DepreciationSchedule extends ReportPage
{
    public static function requires(): ?string
    {
        return 'fixed-assets';
    }

    public static function reportKey(): string
    {
        return 'depreciation-schedule';
    }

    public static function title(): string
    {
        return __('Depreciation Schedule');
    }

    public static function group(): string
    {
        return 'Fixed Assets';
    }

    public static function description(): string
    {
        return "Every asset with its cost, the period's depreciation, accumulated depreciation and book value at the period's end, in the commercial books or the tax books.";
    }

    protected function usesBranch(): bool
    {
        return false;
    }

    protected function defaultFilters(): array
    {
        return parent::defaultFilters() + ['category_id' => null, 'book' => 'commercial'];
    }

    protected function extraFilters(): array
    {
        return [
            Select::make('book')
                ->label(__('Books'))
                ->options(['commercial' => __('Commercial'), 'fiscal' => __('Fiscal (tax)')])
                ->default('commercial')
                ->selectablePlaceholder(false)
                ->native(false)
                ->live(),
            Select::make('category_id')
                ->label(__('Asset category'))
                ->options(fn () => AssetCategory::query()->orderBy('name')->pluck('name', 'id'))
                ->placeholder(__('All categories'))
                ->nullable()
                ->native(false)
                ->live(),
        ];
    }

    protected function rows(): array
    {
        $categoryId = $this->filters['category_id'] ?? null;

        return CashAndAssetReports::depreciationSchedule($this->period(), $categoryId ? (int) $categoryId : null, (string) ($this->filters['book'] ?? 'commercial'));
    }

    protected function columns(): array
    {
        return [
            static::text('number', __('Asset'))->fontFamily('mono'),
            static::text('name', __('Name')),
            static::text('category', __('Category')),
            static::date('usage_date', __('In use from')),
            static::text('method', __('Method')),
            static::text('life', __('Life (months)'))->alignEnd(),
            static::money('cost', __('Cost')),
            static::money('period', __('This period')),
            static::money('accumulated', __('Accumulated')),
            static::money('book_value', __('Book value')),
            static::text('status', __('Status')),
        ];
    }

    protected function exportHeaders(): array
    {
        return [__('Asset'), __('Name'), __('Category'), __('In use from'), __('Method'), __('Life (months)'), __('Cost'), __('This period'), __('Accumulated'), __('Book value'), __('Status')];
    }

    protected function exportRow(array $row): array
    {
        return [
            $row['number'],
            $row['name'],
            $row['category'],
            $row['usage_date'],
            $row['method'],
            $row['life'],
            $row['cost'],
            $row['period'],
            $row['accumulated'],
            $row['book_value'],
            $row['status'],
        ];
    }
}
