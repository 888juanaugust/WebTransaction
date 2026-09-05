<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Credit\CollectionDesk;
use App\Domain\Credit\CollectionOutcome;
use App\Domain\Credit\ContactMethod;
use App\Filament\Navigation\SidebarGroups;
use App\Models\CollectionContact;
use App\Models\Invoice;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The collector's morning, in the order they would work it.
 *
 * Who promised to pay today, who promised and did not, and who is overdue with
 * nobody having called at all. Three queues rather than one long list, because
 * they are three different conversations: a reminder, a confrontation, and a
 * first call.
 *
 * Everything here is recorded, nothing is settled. Pressing a button on this
 * screen never moves money — a payment is still Finance's entry against the
 * invoice, and a promise that quietly reduced a balance would make the ageing
 * report lie.
 */
class Penagihan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PENJUALAN;

    protected static ?string $navigationLabel = 'Penagihan';

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'penagihan';

    protected string $view = 'filament.pages.penagihan';

    public function getTitle(): string
    {
        return 'Penagihan';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canSeeCreditData() ?? false;
    }

    /**
     * How much chasing is waiting — promises due today and promises already
     * broken. Deliberately not the whole overdue list: a badge that counts
     * everything late reads as a number nobody can ever clear.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $daftar = app(CollectionDesk::class)->worklist(auth()->user());
        $jumlah = $daftar['janji_hari_ini']->count() + $daftar['janji_meleset']->count();

        return $jumlah > 0 ? (string) $jumlah : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return array<string, Collection<int, Invoice>> */
    public function daftar(): array
    {
        return app(CollectionDesk::class)->worklist(auth()->user());
    }

    public function janjiUntuk(Invoice $invoice): ?CollectionContact
    {
        return app(CollectionDesk::class)->janjiBerlaku($invoice);
    }

    public function riwayat(Invoice $invoice): Collection
    {
        return app(CollectionDesk::class)->riwayat($invoice);
    }

    public function catatAction(): Action
    {
        return Action::make('catat')
            ->label('Catat kontak')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->size('xs')
            ->modalHeading('Catat hasil penagihan')
            ->modalDescription('Ini catatan pembicaraan, bukan pembayaran. Uang tetap dicatat '
                .'Keuangan lewat faktur.')
            ->schema([
                Select::make('cara')
                    ->label('Cara menghubungi')
                    ->options(collect(ContactMethod::cases())
                        ->mapWithKeys(fn (ContactMethod $c) => [$c->value => $c->label()])->all())
                    ->default(ContactMethod::Telepon->value)
                    ->required(),

                Select::make('hasil')
                    ->label('Hasilnya')
                    ->options(collect(CollectionOutcome::cases())
                        ->mapWithKeys(fn (CollectionOutcome $h) => [$h->value => $h->label()])->all())
                    ->default(CollectionOutcome::JanjiBayar->value)
                    ->live()
                    ->required(),

                // Only the promise outcome asks for a date and an amount —
                // the others have nothing to promise.
                DatePicker::make('janji_tanggal')
                    ->label('Janji bayar tanggal')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->minDate(today())
                    ->required(fn ($get) => $get('hasil') === CollectionOutcome::JanjiBayar->value)
                    ->visible(fn ($get) => $get('hasil') === CollectionOutcome::JanjiBayar->value),

                TextInput::make('janji_rupiah')
                    ->label('Jumlah yang dijanjikan (Rp)')
                    ->numeric()
                    ->helperText('Kosongkan bila mereka menjanjikan seluruh sisanya.')
                    ->visible(fn ($get) => $get('hasil') === CollectionOutcome::JanjiBayar->value),

                Textarea::make('catatan')->label('Catatan')->rows(2)
                    ->placeholder('Apa kata mereka.'),
            ])
            ->action(function (array $arguments, array $data) {
                $invoice = Invoice::query()->find($arguments['invoice'] ?? 0);

                if ($invoice === null) {
                    return;
                }

                try {
                    app(CollectionDesk::class)->record(
                        invoice: $invoice,
                        actor: auth()->user(),
                        cara: ContactMethod::from($data['cara']),
                        hasil: CollectionOutcome::from($data['hasil']),
                        janjiTanggal: ($data['janji_tanggal'] ?? null)
                            ? Carbon::parse($data['janji_tanggal'])
                            : null,
                        janjiRupiah: ($data['janji_rupiah'] ?? null) !== null
                            && $data['janji_rupiah'] !== ''
                            ? (int) $data['janji_rupiah']
                            : null,
                        catatan: $data['catatan'] ?? null,
                    );

                    Notification::make()->title('Kontak dicatat')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Tidak bisa dicatat')
                        ->body($e->getMessage())->danger()->send();
                }
            });
    }
}
