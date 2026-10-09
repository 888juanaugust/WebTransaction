<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\PriceListImports;

use App\Client\Domain\PriceList\ImportDiff;
use App\Client\Filament\Resources\PriceListImports\Pages\CreatePriceListImport;
use App\Client\Filament\Resources\PriceListImports\Pages\ListPriceListImports;
use App\Client\Filament\Resources\PriceListImports\Pages\ViewPriceListImport;
use App\Client\Models\PriceListImport;
use App\Client\Screens\CentralScreen;
use App\Domain\Shared\Format;
use App\Filament\Support\ErpResource;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Price List: the uploads of the supplier's list, each reviewed against the list in force and published as the next version. */
class PriceListImportResource extends ErpResource
{
    protected static ?string $model = PriceListImport::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $modelLabel = 'Price list import';

    protected static ?string $recordTitleAttribute = 'original_filename';

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::PriceList;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('File'))->schema([
                FileUpload::make('stored_path')->label(__('Price list file'))
                    ->disk('local')->directory(config('pricelist.directory', 'price-lists'))->visibility('private')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'text/csv', 'text/plain'])
                    ->maxSize(20480)->required()->storeFileNamesIn('original_filename')
                    ->helperText(__('An .xlsx workbook or a CSV, up to 20 MB. The file is kept with the version it becomes.')),
                Radio::make('format')->label(__('Format'))->options([
                    PriceListImport::CANONICAL => __('The company\'s format (what Export writes)'),
                    PriceListImport::SUPPLIER => __('The supplier\'s raw workbook'),
                ])->default(PriceListImport::CANONICAL)->required(),
            ]),
            Section::make(__('Taking effect'))->columns(2)->schema([
                DatePicker::make('effective_from')->label(__('Effective from'))->native(false)->default(today())->required(),
                Checkbox::make('is_full_replacement')->label(__('This file replaces the whole list'))
                    ->helperText(__('Only then are items missing from the file switched off. Otherwise they keep their price and stay on sale, and the diff counts them.')),
                Textarea::make('note')->label(__('fields.memo'))->rows(2)->columnSpanFull(),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['uploadedBy', 'version']))
            ->columns([
                TextColumn::make('original_filename')->label(__('File'))->searchable()->weight('medium')
                    ->description(fn (PriceListImport $r) => $r->isCanonical() ? __('company format') : __('supplier workbook')),
                TextColumn::make('uploadedBy.name')->label(__('Uploaded by'))->placeholder('—'),
                TextColumn::make('created_at')->label(__('Uploaded'))->formatStateUsing(fn ($state) => Format::dateTime($state))->sortable(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state) => self::statusLabel($state))
                    ->color(fn (string $state) => match ($state) {
                        PriceListImport::PUBLISHED => 'success',
                        PriceListImport::FAILED => 'danger',
                        PriceListImport::PARSED => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('row_count')->label(__('Rows'))->alignEnd(),
                TextColumn::make('blocker_count')->label(__('Blocked'))->alignEnd()->color(fn ($state) => (int) $state > 0 ? 'danger' : null),
                TextColumn::make('diff')->label(__('Diff'))->state(fn (PriceListImport $r) => ImportDiff::summary($r->diff))->wrap(),
                TextColumn::make('brake')->label(__('Brake'))->badge()->state(fn (PriceListImport $r) => $r->brakeTripped() ? __('tripped') : '—')->color(fn (PriceListImport $r) => $r->brakeTripped() ? 'danger' : 'gray'),
                TextColumn::make('version_id')->label(__('Version'))->formatStateUsing(fn ($state) => $state ? "#{$state}" : '—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(fn () => collect([PriceListImport::UPLOADED, PriceListImport::PARSING, PriceListImport::PARSED, PriceListImport::FAILED, PriceListImport::PUBLISHED, PriceListImport::DISCARDED])->mapWithKeys(fn ($s) => [$s => self::statusLabel($s)])->all()),
            ])
            ->recordActions([ViewAction::make()->label(__('Review'))])
            ->emptyStateHeading(__('No price list uploaded yet'))
            ->emptyStateDescription(__('Upload the supplier\'s workbook, or the company\'s own file after editing HARGA.'));
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            PriceListImport::UPLOADED => __('Uploaded'),
            PriceListImport::PARSING => __('Processing'),
            PriceListImport::PARSED => __('Ready to review'),
            PriceListImport::FAILED => __('Failed'),
            PriceListImport::PUBLISHED => __('Published'),
            PriceListImport::DISCARDED => __('Discarded'),
            default => $status,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPriceListImports::route('/'),
            'create' => CreatePriceListImport::route('/create'),
            'view' => ViewPriceListImport::route('/{record}'),
        ];
    }
}
