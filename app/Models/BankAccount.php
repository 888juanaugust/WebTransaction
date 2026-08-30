<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rekening the business runs, bound to its own GL account.
 *
 * The first row is the account the whole system used before multi-bank
 * existed — bound to the original 1-1100 Bank code, so every posted journal
 * already belongs to it. `is_default` is where money lands when nobody
 * chooses; exactly one active row holds it.
 */
#[Fillable(['nama', 'bank', 'nomor', 'atas_nama', 'account_id', 'is_default', 'aktif', 'created_by'])]
class BankAccount extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('aktif', true);
    }

    public function label(): string
    {
        return trim("{$this->nama} — {$this->bank} {$this->nomor}", ' —');
    }
}
