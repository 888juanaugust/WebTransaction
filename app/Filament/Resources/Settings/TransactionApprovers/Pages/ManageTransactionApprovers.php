<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\TransactionApprovers\Pages;

use App\Filament\Resources\Settings\TransactionApprovers\TransactionApproverResource;
use App\Filament\Support\ManageMaster;

class ManageTransactionApprovers extends ManageMaster
{
    protected static string $resource = TransactionApproverResource::class;
}
