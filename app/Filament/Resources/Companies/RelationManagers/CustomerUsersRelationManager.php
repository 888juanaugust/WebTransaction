<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Domain\Audit\AuditLogger;
use App\Models\CustomerUser;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Buyer logins for one customer company.
 *
 * Staff create these; there is no public self-registration, because a
 * wholesale account is opened only after the business is verified and a
 * credit limit agreed.
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
                // Required when creating; left blank on edit means "unchanged".
                ->required(fn (string $operation) => $operation === 'create')
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
                    ->mutateDataUsing(function (array $data) {
                        $data['created_by'] = auth()->id();

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
                    }),
            ])
            ->recordActions([
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
