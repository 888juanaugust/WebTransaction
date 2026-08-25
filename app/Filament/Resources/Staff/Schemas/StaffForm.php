<?php

declare(strict_types=1);

namespace App\Filament\Resources\Staff\Schemas;

use App\Domain\Access\Role;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StaffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Akun')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(120),

                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(190)
                            ->unique(table: User::class, ignoreRecord: true)
                            ->helperText('Dipakai untuk masuk. Bukan alamat pelanggan — akun pembeli '
                                .'dibuat dari halaman perusahaan masing-masing.'),

                        /*
                         * Only on create. Changing an existing password is a
                         * separate action with its own confirmation, because
                         * an editable password field on a form somebody opens
                         * to fix a typo in a name is a password reset waiting
                         * to happen by accident.
                         */
                        TextInput::make('password')
                            ->label('Sandi awal')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(12)
                            ->visibleOn('create')
                            ->helperText('Minimal 12 karakter. Sampaikan langsung ke orangnya dan '
                                .'minta diganti sendiri lewat menu profil setelah masuk pertama kali.'),
                    ]),

                Section::make('Peran')
                    ->description('Peran menentukan apa yang bisa dilihat dan dikerjakan. '
                        .'Satu orang satu peran.')
                    ->schema([
                        Select::make('role')
                            ->label('Peran')
                            ->options(fn () => collect(Role::cases())
                                ->mapWithKeys(fn (Role $r) => [$r->value => $r->label()])
                                ->all())
                            ->default(Role::Sales->value)
                            ->required()
                            ->native(false)
                            /*
                             * Your own row is read-only here. The registrar
                             * refuses it anyway, but a select that accepts the
                             * change and then throws is a worse way to learn
                             * the rule than one that never offered it.
                             */
                            ->disabled(fn (?User $record) => $record !== null
                                && $record->getKey() === auth()->id())
                            ->helperText(fn (?User $record) => $record !== null
                                && $record->getKey() === auth()->id()
                                    ? 'Peran sendiri tidak bisa diubah. Minta pemilik lain yang mengubahnya.'
                                    : static::ringkasanPeran()),
                    ]),
            ]);
    }

    /**
     * What each role can do, in one line, next to the field that grants it.
     *
     * The role matrix lives in CLAUDE.md and in the enum, neither of which is
     * open on the screen at the moment somebody is choosing from a dropdown.
     */
    private static function ringkasanPeran(): string
    {
        return 'Sales: buat order, lihat harga. '
            .'Gudang: pick, kirim, cetak surat jalan — tidak melihat harga atau data kredit. '
            .'Keuangan: konfirmasi pembayaran, kelola kredit dan piutang — tidak mengubah harga order. '
            .'Pemilik: semuanya, termasuk log audit dan pengelolaan staf.';
    }
}
