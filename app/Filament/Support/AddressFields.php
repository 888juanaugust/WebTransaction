<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;

/** The standard's address block: street, city, postcode, province, country. */
final class AddressFields
{
    public static function make(string $prefix, string $label): Fieldset
    {
        $prefix = $prefix === '' ? '' : "{$prefix}_";

        return Fieldset::make($label)
            ->columns(2)
            ->schema([
                Textarea::make("{$prefix}street")->label(__('Street'))->rows(2)->columnSpanFull(),
                TextInput::make("{$prefix}city")->label(__('City'))->maxLength(80),
                TextInput::make("{$prefix}zip_code")->label(__('Postcode'))->maxLength(10),
                TextInput::make("{$prefix}province")->label(__('Province'))->maxLength(80),
                TextInput::make("{$prefix}country")->label(__('Country'))->maxLength(80)->default('Indonesia'),
            ]);
    }
}
