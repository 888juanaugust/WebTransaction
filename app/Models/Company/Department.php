<?php

namespace App\Models\Company;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A department: a cost or profit centre, inside a parent department or at the top. */
class Department extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return list<int> the department and every department under it, for a report filtered by it */
    public static function withDescendants(int $id): array
    {
        $ids = [$id];
        $level = [$id];
        while ($level !== []) {
            $level = static::query()->whereIn('parent_id', $level)->whereNotIn('id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();
            $ids = array_merge($ids, $level);
        }

        return $ids;
    }

    /** @return array<int, string> id → "code · name", indented under its parent */
    public static function options(): array
    {
        $all = static::query()->active()->orderBy('code')->get();
        $out = [];
        $walk = function (?int $parent, int $depth) use (&$walk, &$out, $all): void {
            foreach ($all->where('parent_id', $parent) as $department) {
                $out[$department->id] = str_repeat('— ', $depth).$department->code.' · '.$department->name;
                $walk($department->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return $out;
    }

    public function auditReference(): string
    {
        return "{$this->code} {$this->name}";
    }
}
