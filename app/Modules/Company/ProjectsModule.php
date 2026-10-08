<?php

declare(strict_types=1);

namespace App\Modules\Company;

use App\Domain\Access\MenuKey;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\Project;
use App\Modules\BaseModule;

/** Projects: tags on journal lines and the GL documents, filters on the income statement and the ledger reports. Switched by the Projects feature, off by default. */
final class ProjectsModule extends BaseModule
{
    public static function key(): string
    {
        return 'projects';
    }

    public static function feature(): ?PreferensiKey
    {
        return PreferensiKey::Project;
    }

    public static function menuKeys(): array
    {
        return [MenuKey::Projects];
    }

    public static function morphMap(): array
    {
        return ['project' => Project::class];
    }
}
