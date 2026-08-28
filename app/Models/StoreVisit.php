<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One store visit: who stood where, when, with the photo to show for it.
 * Created only through StoreVisits, which checks the seat.
 */
#[Fillable([
    'sales_user_id', 'company_id', 'latitude', 'longitude',
    'foto_path', 'catatan', 'visited_at',
])]
class StoreVisit extends Model
{
    use HasFactory;
    use HasRegion;

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'visited_at' => 'datetime',
            'foto_dihapus_pada' => 'datetime',
        ];
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
