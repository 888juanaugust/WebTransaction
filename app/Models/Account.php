<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\NormalBalance;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of the chart of accounts.
 *
 * An account is never deleted once anything has posted to it — the entries
 * referencing it are evidence, and an account with no name is a report with a
 * hole in it. Retire one by setting `aktif` false, which stops new postings
 * and leaves the history readable.
 */
#[Fillable([
    'kode', 'nama', 'tipe', 'saldo_normal', 'dapat_diposting', 'parent_id',
    'aktif', 'catatan',
])]
class Account extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'tipe' => AccountType::class,
            'saldo_normal' => NormalBalance::class,
            'dapat_diposting' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function label(): string
    {
        return "{$this->kode} — {$this->nama}";
    }

    /**
     * Look an account up by the code the posting rules name it with.
     *
     * Refuses rather than returns null: a posting rule that cannot find its
     * account has nothing sensible to do next, and a half-posted journal is
     * worse than a failed one.
     */
    public static function byCode(string $kode): self
    {
        $account = static::query()->where('kode', $kode)->first();

        if ($account === null) {
            throw new \RuntimeException(
                "Akun {$kode} tidak ada di bagan akun. Jalankan ChartOfAccountsSeeder."
            );
        }

        return $account;
    }

    public function scopePostable(Builder $query): Builder
    {
        return $query->where('dapat_diposting', true)->where('aktif', true);
    }

    public function scopeOrderedForReport(Builder $query): Builder
    {
        return $query->orderBy('kode');
    }
}
