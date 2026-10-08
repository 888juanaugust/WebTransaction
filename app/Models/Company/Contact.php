<?php

namespace App\Models\Company;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Domain\Shared\Enums\ContactType;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['contact_type' => ContactType::class];
    }
}
