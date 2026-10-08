<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\Users;

use App\Domain\Access\MenuKey;
use App\Domain\Access\UserDeactivation;
use App\Domain\Shared\Format;
use App\Filament\Resources\Settings\Users\Pages\CreateUser;
use App\Filament\Resources\Settings\Users\Pages\EditUser;
use App\Filament\Resources\Settings\Users\Pages\ListUsers;
use App\Filament\Support\ErpResource;
use App\Models\User;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;

/** The Users screen: staff accounts, their access type, groups and branches. */
class UserResource extends ErpResource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'User';

    public static function menuKey(): MenuKey
    {
        return MenuKey::Users;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Account'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label(__('Name'))->required()->maxLength(100),
                    // Who signs in as an existing user is an administrator's to change: an operator managing users could
                    // otherwise reset a colleague's password or email and sign in as them.
                    TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(150)->unique(ignoreRecord: true)
                        ->disabled(fn (?User $record): bool => $record?->exists === true && ! self::actorIsAdministrator()),
                    TextInput::make('phone')->label(__('Mobile number'))->tel()->maxLength(30),
                    TextInput::make('password')
                        ->label(__('Password'))
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation) => $operation === 'create')
                        ->disabled(fn (?User $record): bool => $record?->exists === true && ! self::actorIsAdministrator())
                        ->dehydrated(fn ($state) => filled($state))
                        ->rule(Password::defaults())
                        ->helperText(fn (string $operation) => $operation === 'edit' ? __('Leave blank to keep the current password.') : null),
                    Radio::make('access_type')
                        ->label(__('Access type'))
                        ->options([
                            'operator' => __('Operator: limited to the rights of their access groups'),
                            'administrator' => __('Administrator: every screen, every right'),
                        ])
                        ->default('operator')
                        ->required()
                        ->disabled(fn (): bool => ! self::actorIsAdministrator())
                        ->helperText(fn (): ?string => self::actorIsAdministrator() ? null : __('Only an administrator changes the access type.')),
                    Toggle::make('is_active')->label(__('fields.is_active'))->default(true)->inline(false)
                        ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                        ->helperText(__('Users are never deleted: switching this off ends their access and keeps their name on everything they did.'))
                        ->rule(fn (?User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            $reasons = $record !== null && $record->is_active && ! $value ? UserDeactivation::reasons($record, auth()->user()) : [];
                            if ($reasons !== []) {
                                $fail(implode(' ', $reasons));
                            }
                        }),
                ]),
            Tabs::make('access')->tabs([
                Tab::make(__('Access groups'))->schema([
                    CheckboxList::make('accessGroups')
                        ->label(__('Groups'))
                        ->relationship('accessGroups', 'name', fn ($query) => $query->orderBy('name'))
                        ->disabled(fn (): bool => ! self::actorIsAdministrator())
                        ->columns(3),
                ]),
                Tab::make(__('Branches'))->schema([
                    CheckboxList::make('branches')
                        ->label(__('May work in these branches'))
                        ->helperText(__('A branch open to all users needs no entry here.'))
                        ->relationship('branches', 'name', fn ($query) => $query->where('is_active', true)->orderBy('name'))
                        ->disabled(fn (?User $record): bool => ($record?->is(auth()->user()) ?? false) && ! self::actorIsAdministrator())
                        ->columns(3),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('phone')->label(__('Mobile number'))->placeholder('—'),
                TextColumn::make('email')->label(__('Email'))->searchable(),
                IconColumn::make('two_factor')->label(__('2FA'))->state(fn (User $record) => $record->hasTwoFactor())->boolean(),
                TextColumn::make('access_type')
                    ->label(__('Access type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Format::code($state, 'access_type'))
                    ->color(fn (string $state) => $state === 'administrator' ? 'primary' : 'gray'),
                IconColumn::make('is_active')->label(__('fields.is_active'))->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('access_type')->label(__('Access type'))->options(['operator' => __('Operator'), 'administrator' => __('Administrator')]),
                TernaryFilter::make('is_active')->label(__('Active')),
            ])
            ->recordActions([
                EditAction::make(),
                UserActions::deactivate(),
                UserActions::reactivate(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /** Groups, the access type and administrators' accounts are an administrator's to change. */
    private static function actorIsAdministrator(): bool
    {
        return auth()->user()?->getOriginal('access_type') === 'administrator';
    }
}
