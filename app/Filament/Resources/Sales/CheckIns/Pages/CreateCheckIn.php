<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\CheckIns\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Sales\CheckIns\CheckInResource;
use App\Filament\Support\CreateDocument;
use Illuminate\Support\Carbon;

class CreateCheckIn extends CreateDocument
{
    protected static string $resource = CheckInResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::CheckIn;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['trans_date'] = Carbon::parse($data['checked_in_at'])->toDateString();

        return parent::mutateFormDataBeforeCreate($data);
    }
}
