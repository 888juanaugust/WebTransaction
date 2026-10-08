<?php

namespace App\Models\Company;

use App\Domain\Audit\RecordsActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A saved form, used again from the document's create page. */
class MemorizedTransaction extends Model
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['template' => 'array', 'used_all_user' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memorized_transaction_users');
    }
}
