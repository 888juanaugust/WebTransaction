<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Pengaturan\Fitur;
use App\Domain\Pengaturan\Preferensi as PreferensiBisnis;
use App\Filament\Navigation\SidebarGroups;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * ACCURATE's Preferensi screen: how the system behaves, set by the business.
 *
 * For now it holds the switches over what WebTransaction does that ACCURATE
 * does not (Fitur) and the two debt-age numbers. Each programme phase adds the
 * preferences its ACCURATE screens have, on the tab ACCURATE puts them on.
 * A switch appears here only once the code that honours it exists; the ones
 * still to come are named under the form with the phase that brings them.
 *
 * Owner only until Phase 3 replaces roles with hak akses, when it becomes
 * the special right to change preferences. Every save is audited.
 */
class Preferensi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PENGATURAN;

    protected static ?string $navigationLabel = 'Preferensi';

    protected static ?int $navigationSort = 86;

    protected static ?string $slug = 'preferensi';

    protected string $view = 'filament.pages.preferensi';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        // The Owner, like Pengaturan perusahaan: these change how money and
        // credit behave for everyone.
        return auth()->user()?->role()->canManageStaff() ?? false;
    }

    public function getTitle(): string
    {
        return 'Preferensi';
    }

    public function mount(): void
    {
        $preferensi = app(PreferensiBisnis::class);
        $isi = [];

        foreach (Fitur::diterapkanSemua() as $fitur) {
            $isi['fitur_'.$fitur->value] = $fitur->aktif();
        }

        // Blank while a number follows its default, so saving the form
        // never quietly pins today's default as a stored choice.
        foreach (array_keys(PreferensiBisnis::ANGKA) as $kunci) {
            $isi['angka_'.$kunci] = $preferensi->angkaTersimpan($kunci);
        }

        $this->form->fill($isi);
    }

    public function form(Schema $schema): Schema
    {
        $preferensi = app(PreferensiBisnis::class);
        $tabs = [];

        foreach (Fitur::diterapkanSemua() as $fitur) {
            $tabs[$fitur->tab()][] = Toggle::make('fitur_'.$fitur->value)
                ->label($fitur->label())
                ->helperText($fitur->keterangan());

            foreach (PreferensiBisnis::ANGKA as $kunci => $definisi) {
                if ($definisi['fitur'] === $fitur) {
                    $tabs[$fitur->tab()][] = TextInput::make('angka_'.$kunci)
                        ->label($definisi['label'])
                        ->numeric()
                        ->integer()
                        ->minValue($definisi['min'])
                        ->maxValue($definisi['max'])
                        ->placeholder((string) $preferensi->bawaan($kunci))
                        ->helperText('Kosong = bawaan ('.$preferensi->bawaan($kunci).' hari).');
                }
            }
        }

        $sections = [];

        foreach ($tabs as $tab => $fields) {
            $sections[] = Section::make($tab)->schema($fields);
        }

        return $schema->statePath('data')->components($sections);
    }

    /** @return list<Fitur> switches whose phase has not landed yet, for the note under the form */
    public function saklarMenyusul(): array
    {
        return array_values(array_filter(Fitur::cases(), fn (Fitur $f) => ! $f->diterapkan()));
    }

    public function simpan(): void
    {
        $state = $this->form->getState();
        $fitur = [];
        $angka = [];

        foreach (Fitur::diterapkanSemua() as $f) {
            $fitur[$f->value] = (bool) ($state['fitur_'.$f->value] ?? true);
        }

        foreach (array_keys(PreferensiBisnis::ANGKA) as $kunci) {
            $angka[$kunci] = $state['angka_'.$kunci] ?? null;
        }

        try {
            app(PreferensiBisnis::class)->simpan($fitur, $angka, auth()->user());
        } catch (ValidationException $e) {
            // The domain names fields as angka.<kunci>; the form knows them
            // as data.angka_<kunci>.
            $pesan = [];

            foreach ($e->errors() as $kunci => $isi) {
                $pesan['data.'.str_replace('angka.', 'angka_', $kunci)] = $isi;
            }

            throw ValidationException::withMessages($pesan);
        }

        Notification::make()
            ->title('Preferensi tersimpan')
            ->body('Berlaku untuk permintaan berikutnya — termasuk pemeriksaan kredit dan sapuan piutang malam ini.')
            ->success()
            ->send();
    }
}
