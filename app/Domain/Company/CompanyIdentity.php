<?php

declare(strict_types=1);

namespace App\Domain\Company;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Shared\Format;

/**
 * Who the company is, from Preferences: the letterhead printed documents
 * carry, and the tax identity the VAT return names.
 */
final class CompanyIdentity
{
    public function __construct(private readonly Preferensi $prefs) {}

    /** @return array{name: string, address: string, phone: string, fax: string, email: string, npwp: string} */
    public function letterhead(): array
    {
        return [
            'name' => (string) ($this->prefs->get(PreferensiKey::CompanyName) ?: $this->prefs->get(PreferensiKey::TaxCompanyName) ?: config('app.name')),
            'address' => (string) $this->prefs->get(PreferensiKey::CompanyAddress),
            'phone' => (string) $this->prefs->get(PreferensiKey::CompanyPhone),
            'fax' => (string) $this->prefs->get(PreferensiKey::CompanyFax),
            'email' => (string) $this->prefs->get(PreferensiKey::CompanyEmail),
            'npwp' => (string) $this->prefs->get(PreferensiKey::CompanyNpwp),
        ];
    }

    /** The VAT-registered identity: registered name, tax ID, VAT registration, business type and classification. @return array<string, string> label → value, blanks left out */
    public function taxIdentity(): array
    {
        $pkpDate = $this->prefs->get(PreferensiKey::PkpDate);

        return array_filter([
            __('Registered name') => (string) ($this->prefs->get(PreferensiKey::TaxCompanyName) ?: $this->letterhead()['name']),
            __('NPWP') => (string) $this->prefs->get(PreferensiKey::CompanyNpwp),
            __('VAT registration') => trim((string) $this->prefs->get(PreferensiKey::PkpNumber).($pkpDate ? ' · '.__('since :date', ['date' => Format::date($pkpDate)]) : '')),
            __('Business type') => (string) $this->prefs->get(PreferensiKey::BusinessType),
            __('KLU') => (string) $this->prefs->get(PreferensiKey::Klu),
        ], fn (string $value) => trim($value, ' ·') !== '');
    }

    /** The tax identity on one line, for a report heading. */
    public function taxIdentityLine(): string
    {
        return collect($this->taxIdentity())->map(fn (string $value, string $label) => "{$label} {$value}")->join(' · ');
    }
}
