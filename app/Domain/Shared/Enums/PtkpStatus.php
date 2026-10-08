<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

use Filament\Support\Contracts\HasLabel;

/** The non-taxable income status (PTKP) of an employee. */
enum PtkpStatus: string implements HasLabel
{
    case TK0 = 'TK/0';
    case TK1 = 'TK/1';
    case TK2 = 'TK/2';
    case TK3 = 'TK/3';
    case K0 = 'K/0';
    case K1 = 'K/1';
    case K2 = 'K/2';
    case K3 = 'K/3';

    public function getLabel(): string
    {
        [$status, $dependants] = explode('/', $this->value);
        $married = $status === 'K' ? 'Married' : 'Single';
        $dep = match ($dependants) {
            '0' => __('no dependants'),
            '1' => __('1 dependant'),
            default => "{$dependants} dependants",
        };

        return "{$this->value} · {$married}, {$dep}";
    }
}
