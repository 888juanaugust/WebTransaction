<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\AccessGroups\Pages;

use App\Domain\Access\HakAkses;

/** The rights matrix is not a column: it is lifted out of the form data and synced after the group is saved. */
trait HandlesRights
{
    /** @var array<string, list<string>> */
    private array $rightsInput = [];

    /** @var list<string> */
    private array $specialInput = [];

    private function liftRights(array $data): array
    {
        $this->rightsInput = array_map(fn ($granted) => array_values((array) $granted), $data['rights'] ?? []);
        $this->specialInput = array_values((array) ($data['special_rights'] ?? []));
        unset($data['rights'], $data['special_rights']);

        return $data;
    }

    private function syncRights(): void
    {
        $this->record->syncRights($this->rightsInput);
        $this->record->syncSpecialRights($this->specialInput);
        app(HakAkses::class)->forget();
    }
}
