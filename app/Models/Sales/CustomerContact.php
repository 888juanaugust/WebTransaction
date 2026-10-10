<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Model;

class CustomerContact extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['birth_date' => 'date:Y-m-d'];
    }
}
