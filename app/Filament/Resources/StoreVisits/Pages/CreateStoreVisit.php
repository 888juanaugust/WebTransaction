<?php

declare(strict_types=1);

namespace App\Filament\Resources\StoreVisits\Pages;

use App\Domain\Visits\StoreVisits;
use App\Filament\Resources\StoreVisits\StoreVisitResource;
use App\Models\Company;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * The form a sales fills standing at the store's door.
 *
 * A full page rather than a modal so the phone keyboard, the camera sheet
 * and the geolocation prompt have room to breathe. The coordinates fill
 * themselves from the browser the moment the page opens; the timestamp is
 * not on the form at all, because the moment of recording is recorded, not
 * declared.
 */
class CreateStoreVisit extends CreateRecord
{
    protected static string $resource = StoreVisitResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('company_id')
                ->label('Pelanggan')
                ->options(fn () => Company::query()
                    ->where('status', Company::STATUS_ACTIVE)
                    ->where('sales_user_id', auth()->id())
                    ->orderBy('nama')
                    ->pluck('nama', 'id')
                    ->all())
                ->required()
                ->native(false)
                ->searchable(),

            /*
             * `capture` nudges phones straight to the camera; a gallery pick
             * still works, because the field is the proof and the proof is
             * the photo, however it was taken.
             */
            FileUpload::make('foto_path')
                ->label('Foto toko')
                ->disk(StoreVisits::DISK)
                ->directory('kunjungan')
                ->image()
                ->maxSize(8192)
                ->extraInputAttributes(['capture' => 'environment'])
                ->helperText('Ambil dari kamera — foto etalase atau rak.'),

            ViewField::make('ambil_lokasi')
                ->view('filament.store-visits.geolocation')
                ->dehydrated(false),

            TextInput::make('latitude')
                ->label('Latitude')
                ->readOnly()
                ->helperText('Terisi otomatis dari lokasi HP.'),

            TextInput::make('longitude')
                ->label('Longitude')
                ->readOnly(),

            Textarea::make('catatan')
                ->label('Catatan')
                ->helperText('Stok yang tipis, keluhan, janji — apa pun yang dibawa pulang dari kunjungan.')
                ->maxLength(1000),
        ]);
    }

    /** Through the domain, so the seat rule and the timestamp hold. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(StoreVisits::class)->record(
            sales: auth()->user(),
            company: Company::query()->findOrFail($data['company_id']),
            latitude: $data['latitude'] !== null && $data['latitude'] !== '' ? (float) $data['latitude'] : null,
            longitude: $data['longitude'] !== null && $data['longitude'] !== '' ? (float) $data['longitude'] : null,
            fotoPath: $data['foto_path'] ?? null,
            catatan: $data['catatan'] ?? null,
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
