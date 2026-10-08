<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\Departments;

use App\Domain\Access\MenuKey;
use App\Filament\Resources\Company\Departments\Pages\ManageDepartments;
use App\Filament\Support\MasterResource;
use App\Models\Company\Department;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Departments: cost and profit centres, nested; journal lines and the GL documents carry one, and reports filter by it (with the departments under it). */
class DepartmentResource extends MasterResource
{
    protected static ?string $model = Department::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $modelLabel = 'Department';

    public static function menuKey(): MenuKey
    {
        return MenuKey::Departments;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label(__('Code'))->required()->maxLength(20)->unique(ignoreRecord: true),
            TextInput::make('name')->label(__('fields.name'))->required()->maxLength(100),
            Select::make('parent_id')->label(__('Part of'))
                ->options(fn (?Department $record) => collect(Department::options())->except($record ? Department::withDescendants($record->id) : [])->all())
                ->placeholder(__('Top level'))->native(false),
            self::activeToggle(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parent'))
            ->columns([
                TextColumn::make('code')->label(__('Code'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable()->weight('medium'),
                TextColumn::make('parent.name')->label(__('Part of'))->placeholder('—'),
                self::activeColumn(),
            ])
            ->defaultSort('code')
            ->filters([self::activeFilter()])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDepartments::route('/')];
    }
}
