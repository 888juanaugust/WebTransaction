<?php

declare(strict_types=1);

namespace App\Filament\Support\Columns;

use App\Domain\Shared\Format;
use Filament\Tables\Columns\TextColumn;

/** A date column reading "17 Oct 2026". */
final class Tanggal
{
    public static function make(string $name): TextColumn
    {
        return TextColumn::make($name)
            ->formatStateUsing(fn ($state): string => Format::date($state))
            ->sortable();
    }
}
