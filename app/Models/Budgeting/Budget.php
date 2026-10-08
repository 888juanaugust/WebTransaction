<?php

namespace App\Models\Budgeting;

use App\Domain\Audit\RecordsActivity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One month's budget: an amount per income or expense account (G-08). */
class Budget extends Model
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('sort');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditReference(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function periodLabel(): string
    {
        return CarbonImmutable::create($this->year, $this->month, 1)->translatedFormat('F Y');
    }
}
