<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\Contacts\Pages;

use App\Filament\Resources\Company\Contacts\ContactResource;
use App\Filament\Support\ManageMaster;

class ManageContacts extends ManageMaster
{
    protected static string $resource = ContactResource::class;
}
