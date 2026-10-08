<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Access\BranchLimit;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Audit\Auditor;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Money;
use App\Models\Company\Branch;
use App\Models\Company\PaymentTerm;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemBrand;
use App\Models\Inventory\ItemCategory;
use App\Models\Inventory\Unit;
use App\Models\Purchasing\Vendor;
use App\Models\Sales\Customer;
use App\Models\Sales\PriceCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads customers, vendors and items from a spreadsheet or CSV whose first
 * row names the columns (the template this class also writes). A row with
 * a number updates that record; without one, a new record is numbered from
 * the series. Rows that fail are reported, the rest are imported.
 */
final class MasterImporter
{
    public const KINDS = ['customers' => 'Customers', 'vendors' => 'Vendors', 'items' => 'Items'];

    public function __construct(private readonly NumberGenerator $numbers) {}

    /** @return list<string> the template's column names */
    public static function columns(string $kind): array
    {
        return match ($kind) {
            'customers' => ['number', 'name', 'phone', 'email', 'bill_street', 'bill_city', 'bill_province', 'bill_zip_code', 'wp_number', 'wp_name', 'price_category', 'payment_term', 'credit_limit', 'notes'],
            'vendors' => ['number', 'name', 'phone', 'email', 'bill_street', 'bill_city', 'bill_province', 'bill_zip_code', 'wp_number', 'wp_name', 'payment_term', 'notes'],
            'items' => ['number', 'name', 'item_type', 'category', 'brand', 'unit', 'sell_price', 'purchase_price', 'min_stock', 'upc_no', 'item_tax_code', 'notes'],
            default => throw new RuntimeException(__('Nothing called :kind imports.', ['kind' => $kind])),
        };
    }

    /** The template as CSV text: the header row and one example. */
    public static function template(string $kind): string
    {
        $example = match ($kind) {
            'customers' => ['', 'Acme Trading', '021-555123', 'acme@example.test', 'Jl. Raya 1', 'Jakarta', 'DKI Jakarta', '12345', '01.234.567.8-901.000', 'Acme Trading Ltd', 'Default', 'Net 30', '25000000', ''],
            'vendors' => ['', 'Contoso Supplies', '021-555999', 'sales@contoso.test', 'Jl. Industri 9', 'Bekasi', 'Jawa Barat', '17510', '02.345.678.9-012.000', 'Contoso Supplies', 'Net 30', ''],
            'items' => ['', 'Widget Alpha 1234', 'inventory', 'Spare Parts', 'Alpha', 'PCS', '150000', '100000', '10', '', '', ''],
            default => [],
        };
        $out = fopen('php://memory', 'r+');
        fputcsv($out, self::columns($kind), ',', '"', '\\');
        fputcsv($out, $example, ',', '"', '\\');
        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);

        return $csv;
    }

    /** @return array{created: int, updated: int, errors: list<string>} */
    public function import(string $kind, string $path, ?int $userId = null): array
    {
        $columns = self::columns($kind);
        $rows = $this->rows($path);
        if ($rows === []) {
            throw new RuntimeException(__('The file holds no rows under a header.'));
        }
        $header = array_map(fn ($c) => strtolower(trim((string) $c)), array_shift($rows));
        $missing = array_diff(['name'], $header);
        if ($missing !== []) {
            throw new RuntimeException(__('The header needs a "name" column; see the template.'));
        }

        $result = ['created' => 0, 'updated' => 0, 'errors' => []];
        foreach ($rows as $i => $cells) {
            $row = array_fill_keys($columns, '');
            foreach ($header as $k => $name) {
                if (in_array($name, $columns, true)) {
                    $row[$name] = isset($cells[$k]) ? trim((string) $cells[$k]) : '';
                }
            }
            if (implode('', array_map('strval', $row)) === '') {
                continue;
            }
            try {
                DB::transaction(function () use ($kind, $row, $userId, &$result): void {
                    $outcome = match ($kind) {
                        'customers' => $this->customer($row, $userId),
                        'vendors' => $this->vendor($row, $userId),
                        'items' => $this->item($row, $userId),
                    };
                    $result[$outcome]++;
                });
            } catch (\Throwable $e) {
                $result['errors'][] = 'Row '.($i + 2).' ('.($row['name'] ?: '?').'): '.$e->getMessage();
            }
        }
        Auditor::log('imported', null, $kind, ['created' => $result['created'], 'updated' => $result['updated'], 'errors' => count($result['errors'])]);

        return $result;
    }

    private function customer(array $row, ?int $userId): string
    {
        $this->require($row, ['name']);
        $existing = $row['number'] !== '' ? Customer::query()->where('number', $row['number'])->first() : null;
        $data = [
            'name' => $row['name'], 'work_phone' => $row['phone'] ?: null, 'email' => $row['email'] ?: null,
            'bill_street' => $row['bill_street'] ?: null, 'bill_city' => $row['bill_city'] ?: null, 'bill_province' => $row['bill_province'] ?: null, 'bill_zip_code' => $row['bill_zip_code'] ?: null,
            'wp_number' => $row['wp_number'] ?: null, 'wp_name' => $row['wp_name'] ?: null, 'wp_type' => $row['wp_number'] ? 'npwp' : null, 'notes' => $row['notes'] ?: null,
            'price_category_id' => $this->lookup(PriceCategory::class, $row['price_category'] ?? '', 'price category') ?? PriceCategory::query()->where('is_default', true)->value('id'),
            'payment_term_id' => $this->lookup(PaymentTerm::class, $row['payment_term'] ?? '', 'payment term') ?? PaymentTerm::default()?->id,
        ];
        if (($row['credit_limit'] ?? '') !== '') {
            $this->assertSpecial(HakKhusus::SeeCreditData, __('A credit limit takes the "see credit data" right.'));
            $data['credit_limit_amount_enabled'] = true;
            $data['credit_limit_amount'] = $this->money($row['credit_limit']);
        }
        if ($existing) {
            $this->assertMayUpdate($existing, MenuKey::Customers);
            $existing->update($data);

            return 'updated';
        }
        Customer::query()->create($data + ['number' => $this->number(TransactionType::Customer, $userId), 'branch_id' => $this->branch(), 'is_active' => true]);

        return 'created';
    }

    private function vendor(array $row, ?int $userId): string
    {
        $this->require($row, ['name']);
        $existing = $row['number'] !== '' ? Vendor::query()->where('number', $row['number'])->first() : null;
        $data = [
            'name' => $row['name'], 'work_phone' => $row['phone'] ?: null, 'email' => $row['email'] ?: null,
            'bill_street' => $row['bill_street'] ?: null, 'bill_city' => $row['bill_city'] ?: null, 'bill_province' => $row['bill_province'] ?: null, 'bill_zip_code' => $row['bill_zip_code'] ?: null,
            'wp_number' => $row['wp_number'] ?: null, 'wp_name' => $row['wp_name'] ?: null, 'wp_type' => $row['wp_number'] ? 'npwp' : null, 'notes' => $row['notes'] ?: null,
            'payment_term_id' => $this->lookup(PaymentTerm::class, $row['payment_term'] ?? '', 'payment term') ?? PaymentTerm::default()?->id,
        ];
        if ($existing) {
            $this->assertMayUpdate($existing, MenuKey::Vendors);
            $existing->update($data);

            return 'updated';
        }
        Vendor::query()->create($data + ['number' => $this->number(TransactionType::Vendor, $userId), 'branch_id' => $this->branch(), 'is_active' => true]);

        return 'created';
    }

    private function item(array $row, ?int $userId): string
    {
        $this->require($row, ['name']);
        $existing = $row['number'] !== '' ? Item::query()->where('number', $row['number'])->first() : null;
        $unit = $row['unit'] !== '' ? Unit::query()->where('name', $row['unit'])->first() : Unit::query()->orderBy('id')->first();
        if ($unit === null) {
            throw new RuntimeException(__('Unit ":unit" is not on the Units screen.', ['unit' => $row['unit']]));
        }
        $data = [
            'name' => $row['name'], 'item_type' => in_array($row['item_type'], ['inventory', 'service', 'non_inventory'], true) ? $row['item_type'] : 'inventory',
            'category_id' => $this->lookup(ItemCategory::class, $row['category'] ?? '', 'item category'),
            'brand_id' => $this->lookup(ItemBrand::class, $row['brand'] ?? '', 'brand'),
            'unit1_id' => $unit->id,

            'min_stock' => $row['min_stock'] !== '' ? (float) str_replace(',', '.', $row['min_stock']) : 0,
            'upc_no' => $row['upc_no'] ?: null, 'item_tax_code' => $row['item_tax_code'] ?: null, 'notes' => $row['notes'] ?: null,
        ];
        // A price column left empty keeps the price there is; a purchase price is a cost.
        if (($row['sell_price'] ?? '') !== '') {
            $data['sell_price'] = $this->money($row['sell_price']);
        }
        if (($row['purchase_price'] ?? '') !== '') {
            $this->assertSpecial(HakKhusus::SeeCost, __('A purchase price takes the "see cost" right.'));
            $data['purchase_price'] = $this->money($row['purchase_price']);
        }
        if ($existing) {
            $this->assertMayUpdate($existing, MenuKey::ItemsAndServices);
            $existing->update($data);

            return 'updated';
        }
        Item::query()->create($data + ['number' => $this->number(TransactionType::Item, $userId), 'is_active' => true]);

        return 'created';
    }

    /** Changing a record that exists takes the update right on its screen, and a branch the user may use. */
    private function assertMayUpdate(Model $existing, MenuKey $screen): void
    {
        $user = auth()->user();
        if ($user === null) {
            return; // the console
        }
        if (! app(HakAkses::class)->allows($user, $screen, Hak::Update)) {
            throw new RuntimeException(__(':number exists; changing it takes the update right.', ['number' => $existing->getAttribute('number')]));
        }
        $branch = $existing->getAttribute('branch_id');
        if (! BranchLimit::allows($user, $branch !== null ? (int) $branch : null)) {
            throw new RuntimeException(__('You are not assigned to that branch.'));
        }
    }

    private function assertSpecial(HakKhusus $right, string $message): void
    {
        if (auth()->user() !== null && ! app(HakAkses::class)->allowsSpecial(auth()->user(), $right)) {
            throw new RuntimeException($message);
        }
    }

    /** A new record goes in the user's default branch (the company's default from the console). */
    private function branch(): ?int
    {
        return (auth()->user() !== null ? Branch::defaultFor(auth()->user()) : Branch::default())?->id;
    }

    /** @return list<array<int, mixed>> */
    private function rows(string $path): array
    {
        return SpreadsheetReader::rows($path);
    }

    private function lookup(string $model, string $name, string $what): ?int
    {
        if ($name === '') {
            return null;
        }
        $id = $model::query()->where('name', $name)->value('id');
        if ($id === null) {
            throw new RuntimeException(__('The :what ":name" does not exist; add it first or leave the column blank.', ['what' => $what, 'name' => $name]));
        }

        return (int) $id;
    }

    private function money(string $text): int
    {
        return Money::parse($text === '' ? 0 : $text);
    }

    private function require(array $row, array $fields): void
    {
        foreach ($fields as $field) {
            if (($row[$field] ?? '') === '') {
                throw new RuntimeException(__('":field" is required.', ['field' => $field]));
            }
        }
    }

    private function number(TransactionType $type, ?int $userId): string
    {
        $user = $userId ? User::query()->find($userId) : auth()->user();
        $series = $this->numbers->defaultSeries($type, $user) ?? throw new RuntimeException(__('No number series for :type.', ['type' => $type->getLabel()]));

        return $this->numbers->next($series, CarbonImmutable::today());
    }
}
