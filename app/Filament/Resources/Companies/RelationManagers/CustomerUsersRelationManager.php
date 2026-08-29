<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Onboarding\PortalInviter;
use App\Models\CustomerUser;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Buyer logins for one customer company.
 *
 * Staff create these; there is no public self-registration, because a
 * wholesale account is opened only after the business is verified and a
 * credit limit agreed.
 *
 * There is no password field on create. The account is born with a random
 * password nobody has seen, and the buyer receives an invitation email to
 * set their own — see PortalInviter. Staff who could read a buyer's password
 * could also place orders as that buyer, and "the customer's login is known
 * to our staff" is not a sentence a pilot should start with.
 */
class CustomerUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'customerUsers';

    protected static ?string $title = 'Akun portal pelanggan';

    protected static ?string $modelLabel = 'akun portal';

    /** Only roles that already see credit data may hand out portal access. */
    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->role()->canSeeCreditData() ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama pengguna')
                ->required()
                ->maxLength(255),

            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->unique(table: 'customer_users', ignoreRecord: true),

            TextInput::make('telepon')->label('Telepon')->tel(),

            TextInput::make('password')
                ->label('Kata sandi')
                ->password()
                ->revealable()
                /*
                 * Edit only, as a fallback for a buyer standing at the counter
                 * with a dead mailbox. On create the buyer sets their own via
                 * the emailed invitation, and nobody here ever sees it.
                 */
                ->visible(fn (string $operation) => $operation === 'edit')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText('Kosongkan bila tidak ingin mengubah kata sandi.'),

            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Nonaktifkan untuk mencabut akses tanpa menghapus akun.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('telepon')->label('Telepon')->placeholder('—'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
                TextColumn::make('last_login_at')
                    ->label('Terakhir masuk')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Belum pernah'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Tambah akun portal')
                    ->modalDescription('Pembeli akan menerima email undangan untuk '
                        .'mengatur kata sandinya sendiri — tidak ada kata sandi yang '
                        .'perlu diketik atau dikirim.')
                    ->mutateDataUsing(function (array $data) {
                        $data['created_by'] = auth()->id();
                        // Born locked: a random password nobody has seen. The
                        // buyer replaces it through the invitation link.
                        $data['password'] = Str::password(40);

                        return $data;
                    })
                    ->after(function (CustomerUser $record) {
                        // Granting portal access is an access-control change,
                        // so it belongs in the audit log like any other.
                        app(AuditLogger::class)->log(
                            action: 'customer_portal_access_granted',
                            subject: $record,
                            newValue: ['email' => $record->email, 'company_id' => $record->company_id],
                        );

                        app(PortalInviter::class)->undang($record, auth()->user());
                    })
                    ->successNotificationTitle('Akun dibuat — undangan terkirim ke email pembeli'),
            ])
            ->recordActions([
                Action::make('kirimUndangan')
                    ->label('Kirim undangan')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->requiresConfirmation()
                    ->modalHeading('Kirim ulang undangan portal')
                    ->modalDescription(fn (CustomerUser $record) => 'Email berisi tautan '
                        ."atur-kata-sandi akan dikirim ke {$record->email}. Tautan lama, "
                        .'bila ada, hangus.')
                    ->visible(fn (CustomerUser $record) => $record->is_active)
                    ->action(function (CustomerUser $record) {
                        app(PortalInviter::class)->undang($record, auth()->user());

                        Notification::make()
                            ->title("Undangan terkirim ke {$record->email}")
                            ->success()
                            ->send();
                    }),
                EditAction::make()->label('Ubah'),
                DeleteAction::make()->label('Hapus'),
            ]);
    }

    /** Hashing is handled by the model cast; this keeps blanks from wiping it. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        return $data;
    }
}
