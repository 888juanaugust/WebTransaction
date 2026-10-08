<?php

declare(strict_types=1);

namespace App\Modules\Company;

use App\Domain\Access\MenuKey;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\Department;
use App\Modules\BaseModule;

/** Departments: tags on journal lines and the GL documents, filters on the income statement and the ledger reports. Switched by the Departments feature, off by default. */
final class DepartmentsModule extends BaseModule
{
    public static function key(): string
    {
        return 'departments';
    }

    public static function feature(): ?PreferensiKey
    {
        return PreferensiKey::Department;
    }

    public static function menuKeys(): array
    {
        return [MenuKey::Departments];
    }

    public static function morphMap(): array
    {
        return ['department' => Department::class];
    }
}
