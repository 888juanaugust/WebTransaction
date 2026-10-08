<?php

declare(strict_types=1);

namespace App\Domain\Setup;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Shared\Format;
use App\Domain\Shared\Locales;
use App\Models\Company\Currency;
use App\Models\Preference;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoCompanySeeder;
use Database\Seeders\System\AdminUserSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Brings an empty database to a company's first login: migrations, the
 * System seeders, the company's preferences and base currency, the modules
 * it asked for, the Defaults of those modules, its administrator, and the
 * demo company when asked. Runs once; a second run needs force. Every step
 * is idempotent, so a forced run over an installed database changes only
 * what the options name.
 */
final class Installer
{
    public const MIN_PASSWORD = 12;

    public const INSTALLED_AT = 'system.installed_at';

    /** @var array<string, array{0: string, 1: string, 2: ?string}> code → symbol, name, country */
    public const CURRENCIES = [
        'IDR' => ['Rp', 'Indonesian Rupiah', 'Indonesia'],
        'USD' => ['$', 'US Dollar', 'United States'],
        'SGD' => ['S$', 'Singapore Dollar', 'Singapore'],
        'MYR' => ['RM', 'Malaysian Ringgit', 'Malaysia'],
        'EUR' => ['€', 'Euro', null],
    ];

    /** @var list<string> what happened, one line per step */
    private array $log = [];

    public function __construct(private readonly ModuleRegistry $modules, private readonly Preferensi $prefs) {}

    public function isInstalled(): bool
    {
        return Schema::hasTable('preferences') && Preference::query()->whereKey(self::INSTALLED_AT)->exists();
    }

    /**
     * @return list<string> the steps taken
     *
     * @throws AlreadyInstalled when the database is installed and force was not given
     * @throws InvalidArgumentException on an unknown module key or currency
     */
    public function install(InstallOptions $options): array
    {
        $this->log = [];
        $this->validate($options);

        if ($options->fresh) {
            if (app()->isProduction()) {
                throw new InvalidArgumentException('A fresh install drops every table; refused in production.');
            }
            Artisan::call('migrate:fresh', ['--force' => true]);
            $this->log[] = 'Dropped every table and migrated again.';
        } else {
            Artisan::call('migrate', ['--force' => true]);
            $this->log[] = 'Migrated.';
        }

        if ($this->isInstalled() && ! $options->force) {
            throw new AlreadyInstalled('This database is already installed; pass --force to run the installer over it.');
        }

        foreach (array_diff(DatabaseSeeder::SYSTEM, [AdminUserSeeder::class]) as $seeder) {
            $this->seed($seeder);
        }
        $this->log[] = 'Seeded the system tables.';

        $this->prefs->setMany(array_filter([
            PreferensiKey::CompanyName->value => $options->company,
            PreferensiKey::TaxCompanyName->value => $options->company,
            PreferensiKey::CompanyAddress->value => $options->address,
            PreferensiKey::CompanyPhone->value => $options->phone,
            PreferensiKey::CompanyEmail->value => $options->email,
            PreferensiKey::CompanyNpwp->value => $options->taxId,
            PreferensiKey::FiscalYearStartMonth->value => (string) $options->fiscalYearStart,
            ...($options->locale !== null ? [PreferensiKey::Language->value => $options->locale] : []),
        ], fn ($value) => $value !== null && $value !== ''));
        $this->log[] = "Company: {$options->company}.";

        $this->setBaseCurrency($options->currency);
        $this->setModules($options->enable, $options->disable);

        $admin = $this->ensureAdmin($options);
        $this->log[] = "Administrator: {$admin->email}.";

        foreach (DatabaseSeeder::DEFAULTS as $seeder) {
            $this->seed($seeder);
        }
        foreach ($this->modules->enabled() as $module) {
            foreach ($module::defaultSeeders() as $seeder) {
                $this->seed($seeder);
            }
        }
        $this->log[] = 'Seeded the defaults of every module that is on.';

        if ($options->demo) {
            $this->seed(DemoCompanySeeder::class);
            $this->log[] = 'Seeded the demo company.';
        }

        Preference::query()->updateOrCreate(['key' => self::INSTALLED_AT], ['value' => now()->toIso8601String(), 'updated_by' => $admin->id, 'updated_at' => now()]);
        $this->prefs->forget();
        $this->log[] = 'Installed.';

        return $this->log;
    }

    /** @return list<string> the module keys a Features preference can switch */
    public function switchableModules(): array
    {
        return array_map(fn (string $class) => $class::key(), $this->modules->switchable());
    }

    private function validate(InstallOptions $options): void
    {
        if (trim($options->company) === '') {
            throw new InvalidArgumentException('The company name is required.');
        }
        if (filled($options->adminPassword) && mb_strlen((string) $options->adminPassword) < self::MIN_PASSWORD) {
            throw new InvalidArgumentException('The administrator password needs at least '.self::MIN_PASSWORD.' characters; leave it out to have one made.');
        }
        if ($options->locale !== null && ! isset(Locales::names()[$options->locale])) {
            throw new InvalidArgumentException('Unknown language "'.$options->locale.'"; one of '.implode(', ', array_keys(Locales::names())).'.');
        }
        if (! isset(self::CURRENCIES[strtoupper($options->currency)])) {
            throw new InvalidArgumentException('Unknown currency "'.$options->currency.'"; one of '.implode(', ', array_keys(self::CURRENCIES)).'.');
        }
        if ($options->fiscalYearStart < 1 || $options->fiscalYearStart > 12) {
            throw new InvalidArgumentException('The fiscal year starts in a month from 1 to 12.');
        }
        foreach (array_merge($options->enable, $options->disable) as $key) {
            if (! in_array($key, $this->switchableModules(), true)) {
                throw new InvalidArgumentException('Unknown module "'.$key.'"; the switchable modules are '.implode(', ', $this->switchableModules()).'.');
            }
        }
    }

    private function setBaseCurrency(string $code): void
    {
        $code = strtoupper($code);
        [$symbol, $name, $country] = self::CURRENCIES[$code];
        Currency::query()->where('is_base', true)->where('code', '!=', $code)->update(['is_base' => false]);
        Currency::query()->updateOrCreate(['code' => $code], ['symbol' => $symbol, 'name' => $name, 'country' => $country, 'is_base' => true, 'is_active' => true]);
        Format::forgetSymbol();
        $this->log[] = "Base currency: {$code} ({$symbol}).";
    }

    /**
     * @param  list<string>  $enable
     * @param  list<string>  $disable
     */
    private function setModules(array $enable, array $disable): void
    {
        if ($enable === [] && $disable === []) {
            foreach ((array) config('client.features', []) as $key => $on) {
                if ($this->modules->find($key) !== null) {
                    $on ? $enable[] = $key : $disable[] = $key;
                }
            }
        }
        $features = [];
        foreach ($enable as $key) {
            $features[$this->modules->find($key)::feature()->value] = true;
        }
        foreach ($disable as $key) {
            $features[$this->modules->find($key)::feature()->value] = false;
        }
        if ($features !== []) {
            $this->modules->setFeatures($features);
        }
        $on = array_map(fn (string $class) => $class::key(), array_filter($this->modules->enabled(), fn (string $class) => $class::feature() !== null));
        $this->log[] = 'Optional modules on: '.($on === [] ? 'none' : implode(', ', $on)).'.';
    }

    /**
     * The first administrator. An account that exists already is left exactly as it is (a forced run never resets
     * a password or reactivates anyone); a new one takes the password given, or a random one printed once, and
     * must change it at first sign-in.
     */
    private function ensureAdmin(InstallOptions $options): User
    {
        $existing = User::query()->where('email', $options->adminEmail)->first();
        if ($existing !== null) {
            $this->log[] = "Administrator {$options->adminEmail} exists already: left as it is".(filled($options->adminPassword) ? ' (the password given was not used).' : '.');

            return $existing;
        }
        $password = $options->adminPassword;
        if (blank($password)) {
            $password = Str::password(16, symbols: false);
            $this->log[] = "Administrator password (shown once, changed at first sign-in): {$password}";
        }
        $admin = new User(['name' => $options->adminName, 'email' => $options->adminEmail, 'access_type' => 'administrator', 'is_active' => true]);
        $admin->forceFill(['password' => Hash::make($password), 'password_change_required' => true])->save();

        return $admin;
    }

    private function seed(string $class): void
    {
        Artisan::call('db:seed', ['--class' => $class, '--force' => true]);
    }
}
