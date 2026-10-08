<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

use App\Domain\Shared\Format;
use Filament\Support\Contracts\HasLabel;

enum ContactType: string implements HasLabel
{
    case Customer = 'customer';
    case Vendor = 'vendor';
    case Employee = 'employee';
    case Other = 'other';

    public function getLabel(): string
    {
        return Format::code($this->value, 'contact');
    }
}
