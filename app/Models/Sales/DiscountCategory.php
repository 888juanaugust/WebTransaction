<?php

namespace App\Models\Sales;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use Illuminate\Database\Eloquent\Model;

class DiscountCategory extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];
}
