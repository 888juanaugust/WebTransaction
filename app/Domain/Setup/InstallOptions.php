<?php

declare(strict_types=1);

namespace App\Domain\Setup;

/** What a new installation is told about the company and its first administrator. */
final readonly class InstallOptions
{
    /**
     * @param  list<string>  $enable  module keys to switch on
     * @param  list<string>  $disable  module keys to switch off
     */
    public function __construct(
        public string $company,
        public ?string $address = null,
        public ?string $taxId = null,
        public ?string $phone = null,
        public ?string $email = null,
        public string $currency = 'IDR',
        public int $fiscalYearStart = 1,
        public string $adminName = 'Administrator',
        public string $adminEmail = 'admin@example.test',
        public ?string $adminPassword = null,
        public array $enable = [],
        public array $disable = [],
        public bool $demo = false,
        public bool $fresh = false,
        public bool $force = false,
        public ?string $locale = null,
    ) {}
}
