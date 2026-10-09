<?php

declare(strict_types=1);

namespace App\Client\Filament\Pages;

use App\Client\Access\CentralGroups;
use App\Client\Domain\Teams\TeamAssigner;
use App\Client\Models\TeamCustomer;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\BranchLimit;
use App\Filament\Support\ErpPage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use RuntimeException;

/** Customer Teams: which sales and which marketing is in charge of each customer, assigned by the owner. */
class Teams extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'client.pages.teams';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::Teams;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => BranchLimit::apply(TeamCustomer::query(), auth()->user())->with(['branch', 'salesUser', 'marketingUser']))
            ->columns([
                TextColumn::make('number')->label(__('Customer No.'))->fontFamily('mono')->searchable()->sortable(),
                TextColumn::make('name')->label(__('fields.customer'))->weight('medium')->searchable()->sortable(),
                TextColumn::make('branch.name')->label(__('Branch'))->placeholder('—')->sortable(),
                TextColumn::make('salesUser.name')->label(__('Sales seat'))->placeholder(__('unassigned')),
                TextColumn::make('marketingUser.name')->label(__('Marketing seat'))->placeholder(__('unassigned')),
            ])
            ->filters([
                SelectFilter::make('sales_user_id')->label(__('Sales seat'))->options(fn () => self::members(CentralGroups::SALES)),
                SelectFilter::make('marketing_user_id')->label(__('Marketing seat'))->options(fn () => self::members(CentralGroups::MARKETING)),
                TernaryFilter::make('complete')->label(__('Team complete'))
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('sales_user_id')->whereNotNull('marketing_user_id'),
                        false: fn ($q) => $q->whereNull('sales_user_id')->orWhereNull('marketing_user_id'),
                    ),
            ])
            ->defaultSort('name')
            ->recordActions([$this->assignAction()])
            ->emptyStateHeading(__('No customers yet'));
    }

    private function assignAction(): Action
    {
        return Action::make('assign')
            ->label(__('Assign team'))
            ->icon('heroicon-m-user-plus')
            ->slideOver()
            ->modalHeading(fn (TeamCustomer $record) => __('Team of :name', ['name' => $record->name]))
            ->visible(fn () => static::canUpdate() && auth()->user()?->isAdministrator())
            ->fillForm(fn (TeamCustomer $record) => ['sales_user_id' => $record->sales_user_id, 'marketing_user_id' => $record->marketing_user_id])
            ->schema([
                Select::make('sales_user_id')->label(__('Sales seat'))->options(fn () => self::members(CentralGroups::SALES))->searchable()->native(false)
                    ->helperText(__('A member of the Sales group who works in the customer\'s branch.')),
                Select::make('marketing_user_id')->label(__('Marketing seat'))->options(fn () => self::members(CentralGroups::MARKETING))->searchable()->native(false)
                    ->helperText(__('A member of the Marketing group; marketing sees every branch and approves this customer\'s orders.')),
            ])
            ->action(function (TeamCustomer $record, array $data): void {
                try {
                    app(TeamAssigner::class)->assign(
                        $record,
                        $data['sales_user_id'] ? User::query()->findOrFail($data['sales_user_id']) : null,
                        $data['marketing_user_id'] ? User::query()->findOrFail($data['marketing_user_id']) : null,
                        auth()->user(),
                    );
                    Notification::make()->title(__('Team of :name saved', ['name' => $record->name]))->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Cannot assign'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    /** @return array<int, string> active members of a group, by name */
    private static function members(string $group): array
    {
        return User::query()->where('is_active', true)
            ->whereHas('accessGroups', fn ($q) => $q->where('name', $group))
            ->orderBy('name')->pluck('name', 'id')->all();
    }
}
