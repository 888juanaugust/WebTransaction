<?php

declare(strict_types=1);

namespace App\Filament\Support\Columns;

use App\Domain\Shared\Money;
use Filament\Tables\Columns\TextColumn;

/** A money column: right-aligned, tabular figures, Indonesian grouping, as DESIGN.md asks. */
final class Rupiah
{
    public static function make(string $name): TextColumn
    {
        return TextColumn::make($name)
            ->alignEnd()
            ->formatStateUsing(fn ($state): string => $state === null ? '' : Money::format((int) $state))
            ->extraCellAttributes(['class' => 'ae-money'])
            ->sortable();
    }
}
