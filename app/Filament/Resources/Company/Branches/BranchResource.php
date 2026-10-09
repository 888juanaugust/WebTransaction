<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\Branches;

use App\Domain\Access\MenuKey;
use App\Filament\Resources\Company\Branches\Pages\ManageBranches;
use App\Filament\Support\MasterResource;
use App\Models\Company\Branch;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BranchResource extends MasterResource
{
    protected static ?string $model = Branch::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $modelLabel = 'Branch';

    public static function menuKey(): MenuKey
    {
        return MenuKey::Branches;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('branch')->tabs([
                Tab::make(__('General'))->schema([
                    TextInput::make('code')->label(__('Branch code'))->required()->maxLength(8)->alphaNum()->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state) => strtoupper(trim((string) $state)))
                        ->helperText(__('Short and unique; document numbers carry it (SO-JKT-2610-0001).')),
                    TextInput::make('name')->label(__('fields.name'))->required()->maxLength(100)->unique(ignoreRecord: true),
                    TextInput::make('phone_number')->label(__('Phone number'))->tel()->maxLength(30),
                    Textarea::make('address')->label(__('Address'))->rows(3),
                    TextInput::make('latitude')->label(__('Latitude'))->numeric()->minValue(-90)->maxValue(90)->step(0.000001),
                    TextInput::make('longitude')->label(__('Longitude'))->numeric()->minValue(-180)->maxValue(180)->step(0.000001),
                    Toggle::make('is_default')->label(__('Default branch'))->inline(false),
                    self::activeToggle()->inline(false),
                ]),
                Tab::make(__('Tax info'))->schema([
                    TextInput::make('nitku')->label(__('Business location ID (NITKU)'))->maxLength(30),
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
                self::activeColumn(),
                TextColumn::make('code')->label(__('Branch code'))->fontFamily('mono')->searchable()->sortable()->placeholder('—'),
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable(),
                TextColumn::make('phone_number')->label(__('Phone number'))->placeholder('—'),
                self::usersColumn(),
                IconColumn::make('is_default')->label(__('fields.is_default'))->boolean(),
            ])
            ->defaultSort('name')
            ->filters([self::activeFilter()])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()->hidden(fn (Branch $r) => $r->is_default)]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBranches::route('/')];
    }
}
