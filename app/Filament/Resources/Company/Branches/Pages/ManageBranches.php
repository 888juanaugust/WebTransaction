<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\Branches\Pages;

use App\Filament\Resources\Company\Branches\BranchResource;
use App\Filament\Support\ManageMaster;

class ManageBranches extends ManageMaster
{
    protected static string $resource = BranchResource::class;
}
