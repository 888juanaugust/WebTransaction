<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\BuyerAccounts;

use App\Client\Filament\Resources\BuyerAccounts\Pages\ManageBuyerAccounts;
use App\Client\Models\CustomerUser;
use App\Client\Portal\Domain\BuyerAccounts;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\BranchLimit;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Shared\Format;
use App\Filament\Support\MasterResource;
use App\Models\Sales\Customer;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Buyer Accounts: the customers' logins to the portal — invited by staff, deactivated by staff. */
class BuyerAccountResource extends MasterResource
{
    protected static ?string $model = CustomerUser::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $modelLabel = 'Buyer account';

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::BuyerAccounts;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['customer', 'createdBy']);
        $user = auth()->user();
        if ($user !== null && ! $user->isAdministrator()) {
            $query->whereHas('customer', fn (Builder $c) => BranchLimit::apply($c, $user));
        }

        return $query;
    }

    /** The form for a new account; an existing one changes name, phone and the switch only. */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')->label(__('fields.customer'))
                ->options(fn () => BranchLimit::apply(Customer::query()->where('is_active', true)->orderBy('name'), auth()->user())->pluck('name', 'id'))
                ->searchable()->required()->native(false)
                ->disabled(fn (?CustomerUser $record) => $record !== null)->dehydrated(fn (?CustomerUser $record) => $record === null),
            TextInput::make('name')->label(__('fields.name'))->required()->maxLength(150),
            TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(150)
                ->disabled(fn (?CustomerUser $record) => $record !== null)->dehydrated(fn (?CustomerUser $record) => $record === null)
                ->helperText(fn (?CustomerUser $record) => $record === null ? __('The invitation to set a password goes here.') : null),
            TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(30),
            self::activeToggle()->visible(fn (?CustomerUser $record) => $record !== null),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('customer.name')->label(__('fields.customer'))->searchable()->weight('medium'),
                TextColumn::make('name')->label(__('fields.name'))->searchable(),
                TextColumn::make('email')->label(__('Email'))->searchable()->fontFamily('mono'),
                TextColumn::make('invited_at')->label(__('Invited'))->formatStateUsing(fn ($state) => Format::dateTime($state))->placeholder('—'),
                TextColumn::make('last_login_at')->label(__('Last sign-in'))->formatStateUsing(fn ($state) => Format::dateTime($state))->placeholder(__('never')),
                self::activeColumn(),
            ])
            ->filters([
                SelectFilter::make('customer_id')->label(__('fields.customer'))->options(fn () => Customer::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
                self::activeFilter(),
            ])
            ->recordActions([
                EditAction::make()->label(__('Edit'))->slideOver()
                    ->using(function (CustomerUser $record, array $data): Model {
                        $service = app(BuyerAccounts::class);
                        $record->forceFill(['name' => $data['name'], 'phone' => $data['phone'] ?? null])->save();
                        if (array_key_exists('is_active', $data)) {
                            $data['is_active'] ? $service->activate($record, auth()->user()) : $service->deactivate($record, auth()->user());
                        }

                        return $record->fresh();
                    }),
                Action::make('invite')->label(__('Send invitation'))->icon('heroicon-m-envelope')->color('gray')
                    ->visible(fn (CustomerUser $record) => $record->is_active && HakAkses::can(self::menuKey(), Hak::Update))
                    ->requiresConfirmation()
                    ->modalDescription(fn (CustomerUser $record) => __('A new set-password link goes to :email; earlier links stop working.', ['email' => $record->email]))
                    ->action(function (CustomerUser $record): void {
                        try {
                            app(BuyerAccounts::class)->sendInvitation($record);
                            Notification::make()->title(__('Invitation sent'))->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title(__('Cannot invite'))->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
            ])
            ->emptyStateHeading(__('No buyer accounts'))
            ->emptyStateDescription(__('Invite a person at a customer; they set their password from the email and sign in at /portal.'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageBuyerAccounts::route('/')];
    }
}
