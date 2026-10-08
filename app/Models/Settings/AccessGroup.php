<?php

namespace App\Models\Settings;

use App\Domain\Access\Hak;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\Screens;
use App\Domain\Audit\Auditor;
use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An access group: a named set of screen rights and special rights, held by users. */
class AccessGroup extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'access_group_users');
    }

    public function rights(): HasMany
    {
        return $this->hasMany(AccessGroupRight::class);
    }

    public function specialRights(): HasMany
    {
        return $this->hasMany(AccessGroupSpecialRight::class);
    }

    /**
     * Replaces the group's rights. $matrix is menu key → list of Hak values;
     * a screen absent from it loses every right.
     *
     * @param  array<string, list<string>>  $matrix
     */
    public function syncRights(array $matrix): void
    {
        $before = $this->exists ? $this->load('rights')->rightsMatrix() : [];
        $this->rights()->delete();

        $rows = [];
        foreach ($matrix as $menuKey => $granted) {
            if (Screens::find((string) $menuKey) === null || $granted === []) {
                continue;
            }
            $row = ['menu_key' => $menuKey];
            foreach (Hak::cases() as $hak) {
                $row[$hak->column()] = in_array($hak->value, $granted, true);
            }
            $rows[] = $row;
        }

        if ($rows !== []) {
            $this->rights()->createMany($rows);
        }

        // The grid is replaced whole, so the log carries the screens whose rights changed, before and after.
        $after = $this->load('rights')->rightsMatrix();
        $changed = collect(array_keys($before + $after))->filter(fn (string $key) => ($before[$key] ?? []) != ($after[$key] ?? []))->values();
        if ($changed->isNotEmpty()) {
            Auditor::log('rights_changed', $this, null, [
                'before' => $changed->mapWithKeys(fn (string $key) => [$key => $before[$key] ?? []])->all(),
                'after' => $changed->mapWithKeys(fn (string $key) => [$key => $after[$key] ?? []])->all(),
            ]);
        }
    }

    /** @param  list<string>  $rights  HakKhusus values */
    public function syncSpecialRights(array $rights): void
    {
        $before = $this->specialRights()->pluck('right')->sort()->values()->all();
        $after = collect($rights)->filter(fn ($r) => HakKhusus::tryFrom((string) $r) !== null)->unique()->sort()->values()->all();
        $this->specialRights()->delete();
        $this->specialRights()->createMany(array_map(fn (string $right) => ['right' => $right], $after));
        if ($before !== $after) {
            Auditor::log('special_rights_changed', $this, null, ['added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after))]);
        }
    }

    /** @return array<string, list<string>> menu key → granted Hak values, for the form */
    public function rightsMatrix(): array
    {
        $matrix = [];
        foreach ($this->rights as $row) {
            $granted = [];
            foreach (Hak::cases() as $hak) {
                if ($row->{$hak->column()}) {
                    $granted[] = $hak->value;
                }
            }
            $matrix[$row->menu_key] = $granted;
        }

        return $matrix;
    }

    /** Copies another group's rights and special rights onto this one (the "Copy rights" button). */
    public function copyRightsFrom(AccessGroup $source): void
    {
        $this->syncRights($source->rightsMatrix());
        $this->syncSpecialRights($source->specialRights()->pluck('right')->all());
    }
}
