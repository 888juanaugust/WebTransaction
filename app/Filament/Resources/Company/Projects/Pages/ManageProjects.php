<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\Projects\Pages;

use App\Filament\Resources\Company\Projects\ProjectResource;
use App\Filament\Support\ManageMaster;

class ManageProjects extends ManageMaster
{
    protected static string $resource = ProjectResource::class;
}
