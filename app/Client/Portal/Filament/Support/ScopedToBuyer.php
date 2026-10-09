<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Support;

use App\Client\Portal\Portal;
use Illuminate\Database\Eloquent\Builder;

/** Only the buyer's own customer's rows; a guessed id of another customer's document is not found. */
trait ScopedToBuyer
{
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('customer_id', Portal::customer()->id);
    }
}
