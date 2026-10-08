<?php

namespace App\Models\Inventory;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use Illuminate\Database\Eloquent\Model;

class ItemBrand extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];
}
