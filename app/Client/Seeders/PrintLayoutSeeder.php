<?php

declare(strict_types=1);

namespace App\Client\Seeders;

use App\Domain\Numbering\TransactionType;
use App\Models\Company\PrintLayout;
use Illuminate\Database\Seeder;

/**
 * Central's print layouts: the surat jalan (the delivery's default), the
 * surat pengantar slip and the tanda terima faktur, each on its own
 * template. The base's Standard delivery layout stays, no longer the
 * default. A layout the owner already has is left alone.
 */
class PrintLayoutSeeder extends Seeder
{
    public const LAYOUTS = [
        ['name' => 'Surat Jalan', 'type' => TransactionType::DeliveryOrder, 'template' => 'client.print.surat-jalan', 'default' => true, 'copies' => 3],
        ['name' => 'Surat Pengantar', 'type' => TransactionType::DeliveryOrder, 'template' => 'client.print.surat-pengantar', 'default' => false, 'copies' => 1, 'paper' => 'A5', 'orientation' => 'landscape'],
        ['name' => 'Tanda Terima Faktur', 'type' => TransactionType::InvoiceExchange, 'template' => 'client.print.tanda-terima-faktur', 'default' => true, 'copies' => 2],
    ];

    public function run(): void
    {
        foreach (self::LAYOUTS as $layout) {
            $exists = PrintLayout::query()->where('name', $layout['name'])->where('transaction_type', $layout['type']->value)->exists();
            if ($exists) {
                continue;
            }
            if ($layout['default']) {
                PrintLayout::query()->where('transaction_type', $layout['type']->value)->where('is_default', true)->update(['is_default' => false]);
            }
            PrintLayout::query()->create([
                'name' => $layout['name'],
                'transaction_type' => $layout['type']->value,
                'is_default' => $layout['default'],
                'used_all_user' => true,
                'settings' => array_replace(PrintLayout::DEFAULTS, [
                    'title' => $layout['name'],
                    'template' => $layout['template'],
                    'copies' => $layout['copies'],
                    'paper' => $layout['paper'] ?? 'A4',
                    'orientation' => $layout['orientation'] ?? 'portrait',
                    'show_discount' => false,
                    'show_tax' => false,
                ]),
            ]);
        }
    }
}
