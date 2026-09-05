<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations;

use App\Domain\Quotes\QuotationFlow;
use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Models\Company;
use App\Models\Product;
use App\Models\Quotation;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Penawaran: the written offer before there is an order.
 *
 * Visible to everyone who sees customer credit — the same audience as the
 * orders themselves. Creating one is gated like creating an order, because
 * a quote is a price promise and the people allowed to promise prices are
 * the people allowed to enter orders.
 */
class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PENJUALAN;

    protected static ?string $navigationLabel = 'Penawaran';

    protected static ?string $modelLabel = 'penawaran';

    protected static ?string $pluralModelLabel = 'penawaran';

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'nomor';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canSeeCreditData() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->role()->canCreateOrders() ?? false;
    }

    /**
     * Spelled out rather than inherited, and both are what the default was
     * already doing — the point is that they now say so.
     */
    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    /** A price promised in writing stays readable after it lapses. */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('company_id')
                ->label('Pelanggan')
                ->options(fn () => Company::query()
                    ->where('status', Company::STATUS_ACTIVE)
                    ->orderBy('nama')
                    ->pluck('nama', 'id'))
                ->searchable()
                ->required(),

            DatePicker::make('valid_until')
                ->label('Berlaku sampai')
                ->default(now()->addDays(QuotationFlow::BERLAKU_HARI))
                ->minDate(now())
                ->required(),

            Repeater::make('baris')
                ->label('Barang')
                ->columnSpanFull()
                ->columns(3)
                ->minItems(1)
                ->schema([
                    Select::make('sku')
                        ->label('Barang')
                        ->options(fn () => Product::query()
                            ->where('aktif', true)
                            ->orderBy('kode')
                            ->limit(500)
                            ->get()
                            ->mapWithKeys(fn ($p) => [$p->kode => "{$p->kode} — {$p->description}"]))
                        ->searchable()
                        ->required(),

                    TextInput::make('qty')
                        ->label('Jumlah')
                        ->numeric()
                        ->minValue(1)
                        ->required(),

                    Select::make('unit')
                        ->label('Satuan')
                        ->options(['PCS' => 'PCS', 'SET' => 'SET', 'CTN' => 'Karton'])
                        ->default('PCS')
                        ->required(),
                ]),

            Textarea::make('catatan')
                ->label('Catatan')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable(),
                TextColumn::make('company.nama')->label('Pelanggan')->searchable(),
                TextColumn::make('statusTampil')
                    ->label('Status')
                    ->state(fn (Quotation $record) => $record->statusTampil())
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'diterima' => 'success',
                        'terkirim' => 'info',
                        'kedaluwarsa', 'batal' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('valid_until')->label('Berlaku sampai')->date('d/m/Y'),
                TextColumn::make('total_rupiah')->label('Total')->money('IDR', 0),
                TextColumn::make('order.nomor')->label('Order')->placeholder('—'),
                TextColumn::make('created_at')->label('Dibuat')->since(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotations::route('/'),
            'create' => CreateQuotation::route('/create'),
            'view' => ViewQuotation::route('/{record}'),
        ];
    }
}
