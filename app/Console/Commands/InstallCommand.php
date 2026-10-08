<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Setup\AlreadyInstalled;
use App\Domain\Setup\Installer;
use App\Domain\Setup\InstallOptions;
use App\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Starts a company on a fresh database: asks what the options do not say,
 * then hands everything to the Installer. Without --no-interaction it
 * prompts; with it, the options and the ADMIN_* environment variables decide.
 */
class InstallCommand extends Command
{
    protected $signature = 'erp:install
        {--company= : The company name (defaults to APP_NAME)}
        {--address= : The company address}
        {--tax-id= : The company tax ID}
        {--phone= : The company phone}
        {--email= : The company email}
        {--currency=IDR : The base currency: IDR, USD, SGD, MYR or EUR}
        {--fiscal-year-start=1 : The month the fiscal year starts in (1-12)}
        {--locale= : The language of the screens: en or id (each user may choose their own later)}
        {--admin-name= : The first administrator\'s name}
        {--admin-email= : The first administrator\'s email (defaults to ADMIN_EMAIL)}
        {--admin-password= : The first administrator\'s password (defaults to ADMIN_PASSWORD)}
        {--enable=* : Optional modules to switch on (fixed-assets, tax, approval, budgets, payroll, sales-extras, departments, projects)}
        {--disable=* : Optional modules to switch off}
        {--demo : Seed the demo company}
        {--no-demo : Do not seed the demo company, and do not ask}
        {--fresh : Drop every table first (refused in production)}
        {--force : Run the installer over a database that is already installed}';

    protected $description = 'Install the ERP for a company: migrate, seed, set its preferences, modules and administrator';

    public function handle(Installer $installer, ModuleRegistry $modules): int
    {
        $interactive = $this->input->isInteractive();
        $switchable = $installer->switchableModules();

        $company = $this->option('company') ?: ($interactive ? text('Company name', required: true, default: (string) config('app.name')) : (string) config('app.name'));
        $adminEmail = $this->option('admin-email') ?: env('ADMIN_EMAIL') ?: ($interactive ? text('Administrator email', default: 'admin@example.test', required: true) : 'admin@example.test');
        $adminPassword = $this->option('admin-password') ?: env('ADMIN_PASSWORD') ?: ($interactive ? password('Administrator password (12 characters or more; blank makes one)') : null);
        $enable = self::keys($this->option('enable'));
        $disable = self::keys($this->option('disable'));
        if ($interactive && $enable === [] && $disable === []) {
            $defaultOn = array_values(array_filter($switchable, fn (string $key) => $modules->isEnabled($key)));
            $chosen = multiselect('Optional modules to switch on', options: array_combine($switchable, $switchable), default: $defaultOn, scroll: 10);
            $enable = array_values($chosen);
            $disable = array_values(array_diff($switchable, $chosen));
        }
        $demo = $this->option('demo') || (! $this->option('no-demo') && $interactive && confirm('Seed the demo company to try the screens with?', default: false));

        $options = new InstallOptions(
            company: (string) $company,
            address: $this->option('address') ?: null,
            taxId: $this->option('tax-id') ?: null,
            phone: $this->option('phone') ?: null,
            email: $this->option('email') ?: null,
            currency: strtoupper((string) $this->option('currency')),
            fiscalYearStart: (int) $this->option('fiscal-year-start'),
            adminName: $this->option('admin-name') ?: env('ADMIN_NAME') ?: 'Administrator',
            adminEmail: (string) $adminEmail,
            adminPassword: $adminPassword ?: null,
            enable: array_values(array_filter($enable)),
            disable: array_values(array_filter($disable)),
            demo: (bool) $demo,
            fresh: (bool) $this->option('fresh'),
            force: (bool) $this->option('force'),
            locale: $this->option('locale') ?: null,
        );

        try {
            foreach ($installer->install($options) as $line) {
                $this->line("  {$line}");
            }
        } catch (AlreadyInstalled $e) {
            $this->warn($e->getMessage());

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $this->info("{$options->company} is ready. Log in at ".rtrim((string) config('app.url'), '/').'/admin as '.$options->adminEmail.'.');

        return self::SUCCESS;
    }

    /**
     * Module keys from a repeated option, each value possibly comma-separated: --enable=payroll --enable=tax,budgets.
     *
     * @return list<string>
     */
    private static function keys(mixed $values): array
    {
        $keys = [];
        foreach ((array) $values as $value) {
            foreach (explode(',', (string) $value) as $key) {
                if (trim($key) !== '') {
                    $keys[] = trim($key);
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
