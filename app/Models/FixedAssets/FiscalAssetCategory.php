<?php

namespace App\Models\FixedAssets;

use App\Domain\Audit\RecordsActivity;
use App\Domain\FixedAssets\DepreciationMethod;
use Illuminate\Database\Eloquent\Model;

/** A fiscal asset group (golongan harta): the tax office's method, life and rate. */
class FiscalAssetCategory extends Model
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['depreciation_method' => DepreciationMethod::class, 'useful_life_years' => 'integer', 'rate_percent' => 'decimal:2'];
    }
}
