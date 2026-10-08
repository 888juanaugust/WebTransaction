<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\Customers\Pages;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Filament\Resources\Sales\Customers\CustomerResource;
use App\Filament\Support\PersonalDataAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    /** Without the "see credit data" right the credit fields are hidden, and their values never reach the browser. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! HakAkses::canSpecial(HakKhusus::SeeCreditData)) {
            unset($data['credit_limit_mode'], $data['parent_customer_id'], $data['credit_limit_amount'], $data['credit_limit_amount_enabled'], $data['credit_limit_age_days'], $data['credit_limit_age_enabled']);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [PersonalDataAction::make(), DeleteAction::make()];
    }
}
