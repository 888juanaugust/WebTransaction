<?php

namespace App\Models\Company;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Models\Sales\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A project: work for a customer (or the company itself), with dates and a status. */
class Project extends Model implements HasAuditReference
{
    use RecordsActivity;

    public const STATUSES = ['planned', 'active', 'finished', 'cancelled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return array<int, string> id → "code · name", the projects still open first */
    public static function options(): array
    {
        return static::query()->whereIn('status', ['planned', 'active'])->orderBy('code')->get()
            ->mapWithKeys(fn (self $p) => [$p->id => "{$p->code} · {$p->name}"])->all();
    }

    public function auditReference(): string
    {
        return "{$this->code} {$this->name}";
    }
}
