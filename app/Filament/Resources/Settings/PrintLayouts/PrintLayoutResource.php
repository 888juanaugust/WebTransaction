<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\PrintLayouts;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Settings\PrintLayouts\Pages\CreatePrintLayout;
use App\Filament\Resources\Settings\PrintLayouts\Pages\EditPrintLayout;
use App\Filament\Resources\Settings\PrintLayouts\Pages\ListPrintLayouts;
use App\Filament\Support\MasterResource;
use App\Models\Company\PrintLayout;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Print Layouts: how each document prints — paper, heading, which parts show — one layout per document type, for everyone or chosen users. */
class PrintLayoutResource extends MasterResource
{
    protected static ?string $model = PrintLayout::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static ?string $modelLabel = 'Print layout';

    public static function menuKey(): MenuKey
    {
        return MenuKey::PrintLayouts;
    }

    /** @return array<string, string> transaction type value → label */
    public static function documentOptions(): array
    {
        return collect(TransactionType::cases())->mapWithKeys(fn (TransactionType $type) => [$type->value => $type->getLabel()])->sort()->all();
    }

    private static function show(string $key, string $label): Toggle
    {
        return Toggle::make("settings.{$key}")->label($label)->default((bool) PrintLayout::DEFAULTS[$key]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextInput::make('name')->label(__('fields.name'))->required()->maxLength(100),
                    Select::make('transaction_type')->label(__('Document'))->options(self::documentOptions())->required()->searchable()->native(false),
                    Toggle::make('is_default')->label(__('Default for this document'))->inline(false),
                ]),
            Tabs::make('layout')->tabs([
                Tab::make(__('Layout'))->schema([
                    Grid::make(4)->schema([
                        Select::make('settings.paper')->label(__('Paper'))
                            ->options(['A4' => __('A4'), 'A5' => __('A5'), 'Letter' => __('Letter'), 'Continuous 9.5"' => __('Continuous 9.5"')])
                            ->default(PrintLayout::DEFAULTS['paper'])->required()->native(false),
                        Select::make('settings.orientation')->label(__('Orientation'))
                            ->options(['portrait' => __('Portrait'), 'landscape' => __('Landscape')])
                            ->default(PrintLayout::DEFAULTS['orientation'])->required()->native(false),
                        TextInput::make('settings.title')->label(__('Heading (blank = document name)'))->maxLength(100),
                        TextInput::make('settings.copies')->label(__('Copies'))->numeric()->minValue(1)->maxValue(9)->default(PrintLayout::DEFAULTS['copies']),
                    ]),
                    Grid::make(5)->schema([
                        self::show('show_logo', __('Company logo')),
                        self::show('show_company_address', __('Company address')),
                        self::show('show_tax_id', __('Tax ID')),
                        self::show('show_bank_account', __('Bank account')),
                        self::show('show_signature', __('Signature block')),
                        self::show('show_item_code', __('Item codes')),
                        self::show('show_unit', __('Units')),
                        self::show('show_discount', __('Discount column')),
                        self::show('show_tax', __('Tax column')),
                        self::show('show_notes', __('Notes')),
                    ]),
                    Textarea::make('settings.footer')->label(__('Footer text'))->rows(3),
                ]),
                self::usersTab(),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('users'))
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable(),
                TextColumn::make('transaction_type')->label(__('Document'))->sortable()
                    ->formatStateUsing(fn (string $state): string => TransactionType::tryFrom($state)?->getLabel() ?? $state),
                IconColumn::make('is_default')->label(__('fields.is_default'))->boolean(),
                self::usersColumn(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('transaction_type')->label(__('Document'))->options(self::documentOptions()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrintLayouts::route('/'),
            'create' => CreatePrintLayout::route('/create'),
            'edit' => EditPrintLayout::route('/{record}/edit'),
        ];
    }
}
