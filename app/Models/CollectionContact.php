<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Credit\CollectionOutcome;
use App\Domain\Credit\ContactMethod;
use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One conversation about one unpaid invoice. */
#[Fillable([
    'invoice_id', 'company_id', 'user_id', 'cara', 'hasil',
    'janji_tanggal', 'janji_rupiah', 'catatan', 'dihubungi_pada',
])]
class CollectionContact extends Model
{
    use HasFactory;
    use HasRegion;

    protected function casts(): array
    {
        return [
            'cara' => ContactMethod::class,
            'hasil' => CollectionOutcome::class,
            'janji_tanggal' => 'date',
            'janji_rupiah' => 'integer',
            'dihubungi_pada' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A promise that has not yet come due is still outstanding. */
    public function berjanji(): bool
    {
        return $this->hasil === CollectionOutcome::JanjiBayar && $this->janji_tanggal !== null;
    }
}
