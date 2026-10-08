<?php

namespace App\Models\Company;

use App\Domain\Access\GuardsUserList;
use App\Domain\Audit\RecordsActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A designable print layout for one document type (X-07): paper, heading, what prints, who may use it. */
class PrintLayout extends Model
{
    use GuardsUserList, RecordsActivity;

    public const DEFAULTS = [
        'paper' => 'A4', 'orientation' => 'portrait', 'show_logo' => true, 'title' => null, 'show_company_address' => true,
        'show_tax_id' => true, 'show_bank_account' => true, 'show_signature' => true, 'show_item_code' => true, 'show_unit' => true,
        'show_discount' => true, 'show_tax' => true, 'show_notes' => true, 'footer' => null, 'copies' => 1,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'used_all_user' => 'boolean', 'settings' => 'array'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'print_layout_users');
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return array_replace(self::DEFAULTS, $this->settings ?? []);
    }
}
