<?php

namespace App\Models\Settings;

use App\Domain\Access\GuardsUserList;
use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Domain\Numbering\NumberPattern;
use App\Domain\Numbering\ResetRule;
use App\Domain\Numbering\TransactionType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A number format of the Numbering screen. */
class DocumentSeries extends Model implements HasAuditReference
{
    use GuardsUserList, RecordsActivity;

    protected $table = 'document_series';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'transaction_type' => TransactionType::class,
            'reset_rule' => ResetRule::class,
            'pattern' => 'array',
            'counter_digits' => 'integer',
            'used_all_user' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function pattern(): NumberPattern
    {
        return NumberPattern::fromArray($this->pattern ?? []);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'document_series_users');
    }

    public function counters(): HasMany
    {
        return $this->hasMany(DocumentCounter::class);
    }
}
