<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\PriceListImports\Pages;

use App\Client\Filament\Resources\PriceListImports\PriceListImportResource;
use App\Client\Jobs\ParsePriceListImport;
use App\Client\Models\PriceListImport;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreatePriceListImport extends CreateRecord
{
    protected static string $resource = PriceListImportResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uploaded_by'] = auth()->id();
        $data['status'] = PriceListImport::UPLOADED;
        $data['original_filename'] = $data['original_filename'] ?? basename((string) $data['stored_path']);
        $path = Storage::disk('local')->path((string) $data['stored_path']);
        $data['checksum'] = is_file($path) ? hash_file('sha256', $path) : null;

        return $data;
    }

    protected function afterCreate(): void
    {
        ParsePriceListImport::dispatch($this->record->id)->afterCommit();
        Notification::make()->title(__('File received'))->body(__('It is being processed; the diff appears on the list once done.'))->success()->send();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
