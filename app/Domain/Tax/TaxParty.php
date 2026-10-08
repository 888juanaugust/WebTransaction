<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use Illuminate\Database\Eloquent\Model;

/** The buyer or seller as a tax invoice names them: ID kind and number, registered name, address. */
final class TaxParty
{
    public function __construct(
        public readonly string $idType,   // npwp | nik | passport | other
        public readonly string $idNumber,
        public readonly string $name,
        public readonly string $address,
        public readonly string $idTku,
        public readonly ?string $email = null,
    ) {}

    public static function fromParty(?Model $party): self
    {
        if ($party === null) {
            return new self('other', '', '', '', '');
        }
        $type = (string) ($party->getAttribute('wp_type')?->value ?? $party->getAttribute('wp_type') ?? 'npwp');
        $number = preg_replace('/\D/', '', (string) $party->getAttribute('wp_number')) ?? '';
        $prefix = $party->getAttribute('tax_same_as_bill') === false ? 'tax' : 'bill';
        $address = implode(', ', array_filter([
            $party->getAttribute("{$prefix}_street"),
            $party->getAttribute("{$prefix}_city"),
            $party->getAttribute("{$prefix}_province"),
            $party->getAttribute("{$prefix}_zip_code"),
        ], fn ($v) => filled($v)));
        $idTku = (string) ($party->getAttribute('nitku') ?: ($number !== '' ? $number.config('pajak.coretax.idtku_suffix') : ''));

        return new self($type, $number, (string) ($party->getAttribute('wp_name') ?: $party->getAttribute('name')), $address, $idTku, $party->getAttribute('email'));
    }
}
