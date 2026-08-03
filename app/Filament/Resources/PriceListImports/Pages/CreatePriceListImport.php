<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceListImports\Pages;

use App\Filament\Resources\PriceListImports\PriceListImportResource;
use App\Jobs\ParsePriceListImport;
use App\Models\PriceListImport;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

/**
 * Uploading stores the raw file and queues the parse. It does not touch a
 * single live price — that only happens when somebody publishes the diff.
 */
class CreatePriceListImport extends CreateRecord
{
    protected static string $resource = PriceListImportResource::class;

    /** Whether the operator said this is our own export or a supplier file. */
    private bool $isCanonical = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->isCanonical = ($this->data['format'] ?? 'canonical') === 'canonical';

        $data['uploaded_by'] = auth()->id();
        $data['status'] = PriceListImport::STATUS_UPLOADED;
        $data['original_filename'] = basename((string) $data['stored_path']);

        if (Storage::disk('local')->exists($data['stored_path'])) {
            $data['checksum'] = hash_file('sha256', Storage::disk('local')->path($data['stored_path']));
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        ParsePriceListImport::dispatch($this->record->id, $this->isCanonical);

        Notification::make()
            ->title('Berkas diterima')
            ->body('Berkas sedang diproses. Diff akan muncul di daftar impor setelah selesai.')
            ->success()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
