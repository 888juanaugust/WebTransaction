<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One operator-supplied value. The map from kunci to the config path it
 * overlays lives in PengaturanPerusahaan, which is also the only writer.
 */
#[Fillable(['kunci', 'nilai', 'updated_by'])]
class Pengaturan extends Model
{
    protected $table = 'pengaturan';
}
