<?php

namespace App\Models\Company;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use Illuminate\Database\Eloquent\Model;

/** A delivery term (free on board). */
class Fob extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];
}
