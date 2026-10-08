<?php

namespace App\Models\Settings;

use App\Domain\Audit\RecordsChildActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** One user's exception to their groups: allow or deny one right on one screen. */
class UserRightOverride extends Model
{
    use RecordsChildActivity;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['allowed' => 'boolean'];
    }

    public function auditParent(): ?Model
    {
        return User::query()->find($this->getAttribute('user_id'));
    }
}
