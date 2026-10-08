<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\AccessGroups;

use App\Domain\Access\Hak;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Access\ScreenKey;
use App\Domain\Access\Screens;
use App\Filament\Modul;
use App\Filament\Resources\Settings\AccessGroups\Pages\CreateAccessGroup;
use App\Filament\Resources\Settings\AccessGroups\Pages\EditAccessGroup;
use App\Filament\Resources\Settings\AccessGroups\Pages\ListAccessGroups;
use App\Filament\Support\ErpResource;
use App\Models\Settings\AccessGroup;
use App\Modules\ModuleRegistry;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The Access Groups screen: name, users, the rights matrix per screen, special rights. */
class AccessGroupResource extends ErpResource
{
    protected static ?string $model = AccessGroup::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $modelLabel = 'Access group';

    public static function menuKey(): MenuKey
    {
        return MenuKey::AccessGroups;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('General'))
                ->schema([
                    TextInput::make('name')->label(__('Group name'))->required()->maxLength(100)->unique(ignoreRecord: true),
                    Radio::make('restriction_type')
                        ->label(__('Access restriction'))
                        ->options([
                            'preferences' => __('Follow the restrictions set in Preferences'),
                            'time_window' => __('Restricted to a time window'),
                        ])
                        ->default('preferences')
                        ->live(),
                    Grid::make(2)
                        ->schema([
                            TimePicker::make('restricted_from')->label(__('From'))->seconds(false),
                            TimePicker::make('restricted_until')->label(__('Until'))->seconds(false),
                        ])
                        ->visible(fn (Get $get) => $get('restriction_type') === 'time_window'),
                    Textarea::make('memo')->label(__('fields.memo'))->rows(2),
                ]),
            Tabs::make('group')
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make(__('Users'))->schema([
                        CheckboxList::make('users')
                            ->label(__('Members'))
                            ->relationship('users', 'name', fn ($query) => $query->where('is_active', true)->orderBy('name'))
                            ->columns(3)
                            ->searchable(),
                    ]),
                    Tab::make(__('Screen rights'))->schema(self::rightsSections()),
                    Tab::make(__('Special rights'))->schema([
                        CheckboxList::make('special_rights')
                            ->label(__('Rights not tied to one screen'))
                            ->options(collect(HakKhusus::cases())->mapWithKeys(fn (HakKhusus $r) => [$r->value => $r->label()])->all())
                            ->columns(2)
                            ->bulkToggleable(),
                    ]),
                ]),
        ])->columns(1);
    }

    // What a group may do is an administrator's to decide: someone who may edit groups could otherwise give
    // themselves every right.
    public static function canCreate(): bool
    {
        return auth()->user()?->getOriginal('access_type') === 'administrator' && parent::canCreate();
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->getOriginal('access_type') === 'administrator' && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->getOriginal('access_type') === 'administrator' && parent::canDelete($record);
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->getOriginal('access_type') === 'administrator' && parent::canDeleteAny();
    }

    /** One collapsible section per module, one row of five checkboxes per screen. */
    private static function rightsSections(): array
    {
        $options = collect(Hak::cases())->mapWithKeys(fn (Hak $h) => [$h->value => $h->label()])->all();

        $modules = app(ModuleRegistry::class);

        return array_values(array_filter(array_map(function (Modul $modul) use ($options, $modules) {
            $screens = array_filter(Screens::of($modul), fn (ScreenKey $k) => $k->isReplicated() && $modules->menuKeyEnabled($k));
            if ($screens === []) {
                return null;
            }

            return Section::make($modul->getLabel())
                ->collapsible()
                ->collapsed()
                ->schema(array_map(
                    fn (ScreenKey $key) => CheckboxList::make("rights.{$key->value}")
                        ->label($key->label())
                        ->options($options)
                        ->columns(5)
                        ->bulkToggleable(),
                    array_values($screens),
                ));
        }, Modul::cases())));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Group name'))->searchable()->sortable(),
                TextColumn::make('users.name')->label(__('Users'))->listWithLineBreaks()->limitList(5)->expandableLimitedList(),
                TextColumn::make('rights_count')->label(__('Screens'))->counts('rights')->alignEnd(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccessGroups::route('/'),
            'create' => CreateAccessGroup::route('/create'),
            'edit' => EditAccessGroup::route('/{record}/edit'),
        ];
    }
}
