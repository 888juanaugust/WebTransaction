<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One stored preference; read through App\Domain\Pengaturan\Preferensi, never directly. */
class Preference extends Model
{
    protected $table = 'preferences';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'updated_at' => 'datetime',
        ];
    }
}
