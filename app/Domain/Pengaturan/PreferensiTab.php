<?php

declare(strict_types=1);

namespace App\Domain\Pengaturan;

/** The tabs of the Preferences screen, in the standard's order, plus one for the rules this product keeps. */
enum PreferensiTab: string
{
    case Company = 'company';
    case Features = 'features';
    case Tax = 'tax';
    case Sales = 'sales';
    case Purchasing = 'purchasing';
    case Restrictions = 'restrictions';
    case Attachments = 'attachments';
    case ExtraAttributes = 'extra';
    case DefaultAccounts = 'accounts';
    case Other = 'other';
    case Rules = 'rules';

    public function label(): string
    {
        return match ($this) {
            self::Company => __('Company'),
            self::Features => __('Features'),
            self::Tax => __('Tax'),
            self::Sales => __('Sales'),
            self::Purchasing => __('Purchasing'),
            self::Restrictions => __('Restrictions'),
            self::Attachments => __('Attachments'),
            self::ExtraAttributes => __('Extra Attributes'),
            self::DefaultAccounts => __('Default Accounts'),
            self::Other => __('Other'),
            self::Rules => __('Business Rules'),
        };
    }

    /** @return list<PreferensiKey> */
    public function keys(): array
    {
        return array_values(array_filter(PreferensiKey::cases(), fn (PreferensiKey $key) => $key->tab() === $this));
    }

    /** @return list<PreferensiKey> the keys the Preferences screen offers on this tab */
    public function offeredKeys(): array
    {
        return array_values(array_filter($this->keys(), fn (PreferensiKey $key) => $key->isOffered()));
    }
}
