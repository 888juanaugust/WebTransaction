<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\DocumentSeries;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\NumberPattern;
use App\Domain\Numbering\PatternToken;
use App\Domain\Numbering\ResetRule;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Settings\DocumentSeries\Pages\CreateDocumentSeries;
use App\Filament\Resources\Settings\DocumentSeries\Pages\EditDocumentSeries;
use App\Filament\Resources\Settings\DocumentSeries\Pages\ListDocumentSeries;
use App\Filament\Support\MasterResource;
use App\Models\Settings\DocumentSeries;
use Carbon\CarbonImmutable;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

/** The Numbering screen: a format per transaction type, built from components, with a live example. */
class DocumentSeriesResource extends MasterResource
{
    protected static ?string $model = DocumentSeries::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static ?string $modelLabel = 'Number format';

    public static function menuKey(): MenuKey
    {
        return MenuKey::Numbering;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('series')->tabs([
                Tab::make(__('Numbering'))->schema([
                    Section::make(__('Format'))
                        ->columns(2)
                        ->schema([
                            TextInput::make('name')->label(__('fields.name'))->required()->maxLength(100),
                            Select::make('transaction_type')->label(__('Transaction type'))->options(TransactionType::class)->required()->searchable()->native(false),
                            Select::make('reset_rule')->label(__('Counter reset'))->options(ResetRule::class)->default(ResetRule::Monthly)->required()->native(false),
                            TextInput::make('counter_digits')->label(__('Counter digits'))->numeric()->minValue(1)->maxValue(10)->default(4)->required()->live(),
                            Toggle::make('is_default')->label(__('Default for this transaction type'))->inline(false),
                            self::activeToggle()->inline(false),
                        ]),
                    Section::make(__('Components'))
                        ->description(__('The number is the components in this order. Exactly one must be the counter.'))
                        ->schema([
                            Repeater::make('pattern')->label(__('Pattern'))
                                ->hiddenLabel()
                                ->table([
                                    TableColumn::make(__('Component')),
                                    TableColumn::make(__('Text')),
                                ])
                                ->schema([
                                    Select::make('token')->label(__('Component'))->options(PatternToken::class)->required()->native(false)->live(),
                                    TextInput::make('text')->label(__('Text'))->maxLength(20)->placeholder(__('only for separator text'))->live(onBlur: true)
                                        ->disabled(fn (Get $get) => $get('token') !== PatternToken::Text->value)
                                        ->dehydrated(),
                                ])
                                ->default(NumberPattern::fromFormat('DOC-YYMM-####')->toArray())
                                ->reorderable()
                                ->addActionLabel(__('Add component'))
                                ->minItems(1)
                                ->live()
                                ->rule(fn () => function (string $attribute, $value, $fail) {
                                    try {
                                        NumberPattern::fromArray(array_values((array) $value));
                                    } catch (Throwable) {
                                        $fail(__('A number format needs exactly one counter.'));
                                    }
                                }),
                            Placeholder::make('example')
                                ->label(__('Example for today'))
                                ->content(fn (Get $get): string => self::example($get('pattern'), (int) ($get('counter_digits') ?: 4))),
                        ]),
                ]),
                self::usersTab(),
            ]),
        ])->columns(1);
    }

    private static function example(mixed $pattern, int $digits): string
    {
        try {
            return NumberPattern::fromArray(array_values((array) $pattern))->render(CarbonImmutable::today(), 412, $digits, 'JKT');
        } catch (Throwable) {
            return 'Add exactly one counter component to see an example.';
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('users'))
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable(),
                TextColumn::make('transaction_type')->label(__('Transaction type'))->badge()->color('gray')->sortable(),
                TextColumn::make('example')->label(__('Example'))->state(fn (DocumentSeries $r) => self::example($r->pattern, $r->counter_digits))->fontFamily('mono'),
                TextColumn::make('reset_rule')->label(__('Reset')),
                self::usersColumn(),
                IconColumn::make('is_default')->label(__('fields.is_default'))->boolean(),
            ])
            ->defaultSort('transaction_type')
            ->filters([
                SelectFilter::make('transaction_type')->label(__('Transaction type'))->options(TransactionType::class),
                self::activeFilter(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentSeries::route('/'),
            'create' => CreateDocumentSeries::route('/create'),
            'edit' => EditDocumentSeries::route('/{record}/edit'),
        ];
    }
}
