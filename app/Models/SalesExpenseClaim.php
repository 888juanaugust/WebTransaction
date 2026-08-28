<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Expenses\ClaimStatus;
use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One claim of road spending by a sales, waiting on finance's key.
 *
 * `status`, the decision fields and the expense link are not fillable:
 * only SalesExpenseClaims moves them, because moving them is what posts
 * money into the books.
 */
#[Fillable([
    'sales_user_id', 'tanggal', 'amount_rupiah', 'keterangan',
])]
class SalesExpenseClaim extends Model
{
    use HasFactory;
    use HasRegion;

    protected $attributes = ['status' => ClaimStatus::Diajukan->value];

    protected function casts(): array
    {
        return [
            'status' => ClaimStatus::class,
            'amount_rupiah' => 'integer',
            'tanggal' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
