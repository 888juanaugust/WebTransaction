<?php

declare(strict_types=1);

namespace App\Filament\Resources\Staff\Schemas;

use App\Domain\Access\Role;
use App\Domain\Regions\RegionContext;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
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
                    ->columns(2)
                    ->schema([
                        Select::make('role')
                            ->label('Peran')
                            ->options(fn () => collect(Role::cases())
                                ->mapWithKeys(fn (Role $r) => [$r->value => $r->label()])
                                ->all())
                            ->default(Role::Sales->value)
                            ->required()
                            ->native(false)
                            // Live so the region field can hide itself the
                            // moment the role becomes Pemilik.
                            ->live()
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

                        /*
                         * Which region's books this account can see — the
                         * whole of them, and nothing else. Hidden for the
                         * Owner role, whose empty region *is* the grant of
                         * every region; a value here would quietly pin the
                         * one account that must not be pinned.
                         */
                        Select::make('region_id')
                            ->label('Cabang')
                            /*
                             * Defaults to the region the person filling the
                             * form is looking at — with one region that makes
                             * the field invisible work, and with several it is
                             * still the likeliest answer.
                             */
                            ->default(fn () => app(RegionContext::class)->regionId())
                            ->options(fn () => Region::query()
                                ->where('aktif', true)
                                ->orderBy('kode')
                                ->get()
                                ->mapWithKeys(fn (Region $r) => [$r->id => $r->label()])
                                ->all())
                            // Marketing is global like the Owner: no region
                            // field, because there is nothing to choose.
                            // Storage is hidden too: a packer's region is
                            // their warehouse's region, chosen below.
                            ->required(fn (callable $get) => ! in_array($get('role'), [Role::Owner->value, Role::Marketing->value, Role::Storage->value], true))
                            ->native(false)
                            ->hidden(fn (callable $get) => in_array($get('role'), [Role::Owner->value, Role::Marketing->value, Role::Storage->value], true))
                            ->disabled(fn (?User $record) => $record !== null
                                && $record->getKey() === auth()->id())
                            ->helperText('Akun ini hanya melihat data cabang tersebut: '
                                .'stok, pelanggan, order, dan pembukuannya.'),

                        /*
                         * The Gudang role is bound to exactly one warehouse —
                         * the region field disappears because the region
                         * follows the warehouse, never the other way round.
                         * The registrar refuses a second active packer on the
                         * same gudang, so the select shows every warehouse and
                         * lets the refusal carry the explanation.
                         */
                        Select::make('warehouse_id')
                            ->label('Gudang yang dipegang')
                            ->options(fn () => Warehouse::query()
                                ->where('aktif', true)
                                ->orderBy('nama')
                                ->pluck('nama', 'id'))
                            ->required(fn (callable $get) => $get('role') === Role::Storage->value)
                            ->native(false)
                            ->hidden(fn (callable $get) => $get('role') !== Role::Storage->value)
                            ->helperText('Satu gudang satu akun Gudang. Cabang akun ini mengikuti '
                                .'cabang gudangnya.'),
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
        return 'Sales: kunjungan, buat order untuk pelanggan (menunggu persetujuan marketing), ajukan pelunasan tunai. '
            .'Marketing: global semua cabang — setujui/tolak transaksi, pantau piutang pelanggannya, ajukan pelunasan. '
            .'Inventori: stok, katalog, dan daftar harga — tidak melihat piutang pelanggan. '
            .'Gudang: satu akun per gudang — antrean packing, pick list, surat jalan gudangnya sendiri. '
            .'Keuangan: konfirmasi pembayaran, verifikasi pelunasan piutang, pembukuan. '
            .'Pemilik: semuanya, termasuk log audit, cabang, dan pengelolaan staf.';
    }
}
