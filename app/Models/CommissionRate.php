<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's commission rate from one date onward.
 *
 * Append-only by convention: a change is a new row with a later
 * `berlaku_mulai`, never an UPDATE — so a commission report for a closed
 * month prints the same forever. `basis_poin` is basis points (150 = 1,50%),
 * an integer, because DECIMAL rates invite float arithmetic on money.
 */
#[Fillable(['user_id', 'basis_poin', 'berlaku_mulai', 'set_by'])]
class CommissionRate extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'basis_poin' => 'integer',
            'berlaku_mulai' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
