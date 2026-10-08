<?php

namespace App\Models\Settings;

use Illuminate\Database\Eloquent\Model;

class AccessGroupRight extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_create' => 'boolean',
            'can_update' => 'boolean',
            'can_delete' => 'boolean',
            'can_print' => 'boolean',
        ];
    }
}
