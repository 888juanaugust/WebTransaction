<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\Projects;

use App\Domain\Access\MenuKey;
use App\Filament\Resources\Company\Projects\Pages\ManageProjects;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\MasterResource;
use App\Models\Company\Project;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Projects: work for a customer with dates and a status; journal lines and the GL documents carry one, and reports filter by it. */
class ProjectResource extends MasterResource
{
    protected static ?string $model = Project::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $modelLabel = 'Project';

    public static function menuKey(): MenuKey
    {
        return MenuKey::Projects;
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return ['planned' => __('Planned'), 'active' => __('Active'), 'finished' => __('Finished'), 'cancelled' => __('Cancelled')];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label(__('Code'))->required()->maxLength(20)->unique(ignoreRecord: true),
            TextInput::make('name')->label(__('fields.name'))->required()->maxLength(150),
            Select::make('customer_id')->label(__('fields.customer'))->relationship('customer', 'name')->searchable()->preload()->native(false),
            DatePicker::make('start_date')->label(__('Starts'))->native(false),
            DatePicker::make('end_date')->label(__('Ends'))->native(false)->afterOrEqual('start_date'),
            Select::make('status')->label(__('fields.status'))->options(self::statuses())->default('planned')->required()->native(false),
            Textarea::make('notes')->label(__('fields.memo'))->rows(2),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('customer'))
            ->columns([
                TextColumn::make('code')->label(__('Code'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable()->weight('medium'),
                TextColumn::make('customer.name')->label(__('fields.customer'))->placeholder('—'),
                Tanggal::make('start_date')->label(__('Starts'))->placeholder('—'),
                Tanggal::make('end_date')->label(__('Ends'))->placeholder('—'),
                TextColumn::make('status')->label(__('fields.status'))->badge()->formatStateUsing(fn (string $state) => self::statuses()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success', 'finished' => 'gray', 'cancelled' => 'danger', default => 'warning'
                    }),
            ])
            ->defaultSort('code')
            ->filters([SelectFilter::make('status')->label(__('fields.status'))->options(self::statuses())])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageProjects::route('/')];
    }
}
