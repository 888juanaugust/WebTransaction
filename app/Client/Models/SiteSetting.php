<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Domain\Audit\HasAuditReference;
use Illuminate\Database\Eloquent\Model;

/** One overridden key of the public site's copy. Written through SiteSettings, which audits the change. */
class SiteSetting extends Model implements HasAuditReference
{
    protected $table = 'site_settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['key', 'value', 'updated_by', 'updated_at'];

    protected function casts(): array
    {
        return ['value' => 'array', 'updated_at' => 'datetime'];
    }

    public function auditReference(): string
    {
        return (string) $this->key;
    }
}
