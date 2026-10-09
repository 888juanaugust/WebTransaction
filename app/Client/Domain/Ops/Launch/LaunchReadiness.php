<?php

declare(strict_types=1);

namespace App\Client\Domain\Ops\Launch;

use App\Client\Domain\Ops\Integrity\LedgerIntegrity;
use App\Client\Models\BackupRun;
use App\Client\Models\LaunchAttestation;
use App\Client\Models\PriceListVersion;
use App\Client\Site\Copy;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Sales\SalesInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * What still stands between the system and going live: eleven checks the
 * system makes (recomputed on every read; an exception is a failed check,
 * never a crash) and six items a person attests. Ready when nothing is
 * outstanding.
 */
class LaunchReadiness
{
    /** @var list<string> the items a person attests, in order */
    public const ATTESTED = ['pse', 'kbli', 'legal_reviewed', 'commercial_values', 'invoice_format', 'restore_drilled'];

    /** @var array<string, string> site contact values as shipped, which are not a company's */
    private const PLACEHOLDER_CONTACT = ['contact.phone' => '+62 21 0000 0000', 'contact.whatsapp' => '+62 800 0000 0000', 'contact.email' => 'sales@example.com'];

    /** @var list<LaunchCheck>|null */
    private ?array $memo = null;

    public function __construct(private readonly Preferensi $prefs, private readonly LedgerIntegrity $integrity) {}

    /** @return list<LaunchCheck> */
    public function checks(): array
    {
        return $this->memo ??= [...$this->automatic(), ...$this->attested()];
    }

    public function forget(): void
    {
        $this->memo = null;
    }

    /** @return list<LaunchCheck> failing checks, the system's first */
    public function outstandingChecks(): array
    {
        $failing = array_values(array_filter($this->checks(), fn (LaunchCheck $c) => ! $c->passed));
        usort($failing, fn (LaunchCheck $a, LaunchCheck $b) => ($b->automatic <=> $a->automatic));

        return $failing;
    }

    public function outstanding(): int
    {
        return count(array_filter($this->checks(), fn (LaunchCheck $c) => ! $c->passed));
    }

    public function isReady(): bool
    {
        return $this->outstanding() === 0;
    }

    /** @return array<string, array{title: string, description: string}> */
    public static function attestedItems(): array
    {
        return [
            'pse' => ['title' => __('PSE Lingkup Privat registered'), 'description' => __('Registration with Komdigi through OSS (PB-UMKU) before customers use the portal. Note the PB-UMKU number.')],
            'kbli' => ['title' => __('KBLI covers online wholesale'), 'description' => __('The business licence lists the codes for wholesale of automotive parts and trade through the system. Note the codes.')],
            'legal_reviewed' => ['title' => __('Legal pages reviewed'), 'description' => __('The privacy policy and the terms of sale were read by someone who answers for them. Note who and when.')],
            'commercial_values' => ['title' => __('Commercial values set'), 'description' => __('The late fee, the claim window and the dispute forum on the Website screen are the ones the company applies.')],
            'invoice_format' => ['title' => __('Invoice format checked'), 'description' => __('A printed invoice carries the right name, NPWP, address and bank account; the Coretax export imports without error.')],
            'restore_drilled' => ['title' => __('Restore drilled'), 'description' => __('php artisan central:restore --into=a scratch database ran from last night\'s backup and the figures were checked. Note the date.')],
        ];
    }

    /** @return list<LaunchCheck> */
    private function automatic(): array
    {
        return array_map(fn (array $one) => $this->survives($one[0], $one[1], $one[2]), [
            ['environment', __('Production environment'), fn () => $this->environment()],
            ['mail', __('Mail configured'), fn () => $this->mail()],
            ['company_identity', __('Company identity'), fn () => $this->companyIdentity()],
            ['site_contact', __('Site contact details'), fn () => $this->siteContact()],
            ['partners', __('Partners on the site'), fn () => $this->partners()],
            ['price_list', __('Price list published'), fn () => $this->priceList()],
            ['staff_passwords', __('Staff passwords'), fn () => $this->staffPasswords()],
            ['two_factor', __('Administrator second factor'), fn () => $this->twoFactor()],
            ['backup', __('Off-site backup'), fn () => $this->backup()],
            ['integrity', __('Ledger integrity'), fn () => $this->integrity()],
            ['first_invoice', __('A real invoice'), fn () => $this->firstInvoice()],
        ]);
    }

    private function survives(string $key, string $title, callable $check): LaunchCheck
    {
        try {
            return $check();
        } catch (Throwable $e) {
            return LaunchCheck::checked($key, $title, __('This check could not run, so its state is unknown.'), false, __('Could not check: :error', ['error' => $e->getMessage()]), __('Check the database and Redis, then reload.'));
        }
    }

    private function environment(): LaunchCheck
    {
        $wrong = [];
        if (config('app.env') !== 'production') {
            $wrong[] = 'APP_ENV='.config('app.env');
        }
        if ((bool) config('app.debug')) {
            $wrong[] = 'APP_DEBUG=true';
        }
        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $wrong[] = 'APP_URL='.config('app.url');
        }

        return LaunchCheck::checked('environment', __('Production environment'), __('APP_ENV=production, APP_DEBUG=false and an https APP_URL: a stack trace on a public page, or a signed link built on http, is the alternative.'), $wrong === [], $wrong === [] ? null : implode(', ', $wrong), __('Set them in .env, then php artisan config:cache'));
    }

    private function mail(): LaunchCheck
    {
        $mailer = (string) config('mail.default');
        $ok = ! in_array($mailer, ['log', 'array', ''], true);

        return LaunchCheck::checked('mail', __('Mail configured'), __('Invitations, tax invoices, the aging notice and the health alert all go by mail; the log mailer sends nothing.'), $ok, __('The mailer is :mailer', ['mailer' => $mailer]), __('Set MAIL_* in .env to the company\'s SMTP'));
    }

    private function companyIdentity(): LaunchCheck
    {
        $missing = [];
        foreach ([[PreferensiKey::CompanyName, __('name')], [PreferensiKey::CompanyAddress, __('address')], [PreferensiKey::CompanyNpwp, 'NPWP']] as [$key, $label]) {
            if (blank($this->prefs->get($key))) {
                $missing[] = $label;
            }
        }

        return LaunchCheck::checked('company_identity', __('Company identity'), __('The name, address and NPWP printed on every document and named on the legal pages.'), $missing === [], $missing === [] ? null : __('Not filled in: :list', ['list' => implode(', ', $missing)]), __('Preferences → Company and Tax'));
    }

    private function siteContact(): LaunchCheck
    {
        $placeholders = [];
        foreach (self::PLACEHOLDER_CONTACT as $key => $placeholder) {
            $value = (string) Copy::value($key);
            if ($value === '' || $value === $placeholder) {
                $placeholders[] = $key;
            }
        }

        return LaunchCheck::checked('site_contact', __('Site contact details'), __('The phone, WhatsApp and email on the public site; the shipped values are placeholders.'), $placeholders === [], $placeholders === [] ? null : __('Still placeholders: :list', ['list' => implode(', ', $placeholders)]), __('Website screen → Contact'));
    }

    private function partners(): LaunchCheck
    {
        $names = array_filter(array_map(fn ($p) => (string) ($p['name'] ?? ''), (array) Copy::value('partners')), fn (string $n) => str_starts_with($n, 'Partner Name'));

        return LaunchCheck::checked('partners', __('Partners on the site'), __('Naming a company as a partner in public is a claim about a real relationship; the shipped names are invented.'), $names === [], $names === [] ? null : __(':count partner(s) still carry the example name', ['count' => count($names)]), __('Website screen → Partners'));
    }

    private function priceList(): LaunchCheck
    {
        $published = PriceListVersion::query()->whereNotNull('published_at')->count();

        return LaunchCheck::checked('price_list', __('Price list published'), __('The system ships no prices; without a published version no order can be priced.'), $published > 0, $published > 0 ? __(':count version(s) published', ['count' => $published]) : __('No version published'), __('Price List → upload → review → publish'));
    }

    private function staffPasswords(): LaunchCheck
    {
        $weak = 0;
        foreach (User::query()->where('is_active', true)->get(['id', 'password']) as $user) {
            $hash = (string) $user->password;
            if ($hash === '') {
                continue;
            }
            if (Cache::rememberForever('ops:weak-password:'.md5($hash), fn () => Hash::check('password', $hash))) {
                $weak++;
            }
        }

        return LaunchCheck::checked('staff_passwords', __('Staff passwords'), __('No active account signs in with the word "password".'), $weak === 0, $weak === 0 ? null : __(':count active account(s) still use "password"', ['count' => $weak]), __('Users → set a password, or let each person reset theirs'));
    }

    private function twoFactor(): LaunchCheck
    {
        $on = $this->prefs->isOn(PreferensiKey::AdministratorTwoFactor);

        return LaunchCheck::checked('two_factor', __('Administrator second factor'), __('Administrators sign in with an authenticator app; an administrator\'s password alone opens everything.'), $on, $on ? null : __('Switched off'), __('Preferences → Restrictions'));
    }

    private function backup(): LaunchCheck
    {
        $run = BackupRun::query()->verified()->where('offsite', true)->latest('started_at')->first();

        return LaunchCheck::checked('backup', __('Off-site backup'), __('A verified backup that left the machine; a copy on the same disk dies with it.'), $run !== null, $run !== null ? __('Last one :when', ['when' => $run->started_at->diffForHumans()]) : __('None yet'), __('Set BACKUP_DISK and BACKUP_ENCRYPTION_KEY, then php artisan central:backup'));
    }

    private function integrity(): LaunchCheck
    {
        $count = count($this->integrity->findings());

        return LaunchCheck::checked('integrity', __('Ledger integrity'), __('Every cache agrees with its ledger.'), $count === 0, $count === 0 ? null : __(':count difference(s)', ['count' => $count]), 'php artisan central:integrity');
    }

    private function firstInvoice(): LaunchCheck
    {
        $count = SalesInvoice::query()->approved()->count();

        return LaunchCheck::checked('first_invoice', __('A real invoice'), __('One order went through to an approved invoice, so the chain is known to work before customers depend on it.'), $count > 0, $count > 0 ? __(':count approved', ['count' => $count]) : __('None yet'), __('Place, approve, deliver and invoice one order'));
    }

    /** @return list<LaunchCheck> */
    private function attested(): array
    {
        $rows = LaunchAttestation::query()->with('attestedBy')->get()->keyBy('key');
        $checks = [];
        foreach (self::attestedItems() as $key => $item) {
            $row = $rows->get($key);
            $checks[] = new LaunchCheck($key, $item['title'], $item['description'], false, $row !== null, null, null, $row?->attestedBy?->name, $row?->attested_at?->toDateTimeString(), $row?->note);
        }

        return $checks;
    }
}
