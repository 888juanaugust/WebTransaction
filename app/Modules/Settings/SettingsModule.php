<?php

declare(strict_types=1);

namespace App\Modules\Settings;

use App\Domain\Access\MenuKey;
use App\Models\Company\PrintLayout;
use App\Models\Settings\AccessGroup;
use App\Models\Settings\DocumentSeries;
use App\Models\User;
use App\Modules\BaseModule;

/** Settings: preferences, access, users, numbering, print layouts. Core. */
final class SettingsModule extends BaseModule
{
    public static function key(): string
    {
        return 'settings';
    }

    public static function menuKeys(): array
    {
        return [MenuKey::Preferences, MenuKey::AccessGroups, MenuKey::Users, MenuKey::Numbering, MenuKey::PrintLayouts, MenuKey::AddOnStore, MenuKey::FinancingProgram];
    }

    public static function morphMap(): array
    {
        return [
            'user' => User::class,
            'access_group' => AccessGroup::class,
            'document_series' => DocumentSeries::class,
            'print_layout' => PrintLayout::class,
        ];
    }
}
