<?php

namespace App\Models\Settings;

use Illuminate\Database\Eloquent\Model;

/** The last number drawn for a series in one reset period; advanced only by NumberGenerator. */
class DocumentCounter extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $primaryKey = null;

    public $incrementing = false;
}
