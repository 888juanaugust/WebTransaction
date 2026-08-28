<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'bank_code', 'account_number', 'external_id', 'gateway_id', 'status'])]
class VirtualAccount extends Model
{
    use HasFactory;
    use HasRegion;

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
