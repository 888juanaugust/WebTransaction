<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Settlement\Contracts\PaidByPayment;
use App\Domain\Settlement\SettlementService;
use App\Domain\Shared\Format;
use App\Models\Company\PayrollEntry;
use App\Models\GeneralLedger\ExpenseAccrual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/** The open expense accruals and payroll entries a payment line can settle, keyed "type:id". */
final class AccrualFields
{
    /** @return list<class-string<Model&PaidByPayment>> */
    public const DOCUMENTS = [ExpenseAccrual::class, PayrollEntry::class];

    /** @return Collection<string, array{model: Model, label: string, balance: int, account_id: int}> */
    public static function openFor(?string $alsoKey = null): Collection
    {
        $settlement = app(SettlementService::class);
        $out = collect();
        foreach (self::DOCUMENTS as $class) {
            $docs = $class::query()->where('payment_status', '!=', 'paid')->orderBy('trans_date')->get()->filter(fn ($doc) => app(ApprovalEngine::class)->isApproved($doc));
            foreach ($docs as $doc) {
                $balance = $settlement->balance($doc);
                if ($balance <= 0) {
                    continue;
                }
                $out[$doc->getMorphClass().':'.$doc->id] = self::row($doc, $balance);
            }
        }
        // The document a saved line already settles stays selectable even once it is paid.
        if ($alsoKey !== null && ! $out->has($alsoKey) && ($doc = self::resolve($alsoKey)) !== null) {
            $out[$alsoKey] = self::row($doc, $settlement->balance($doc));
        }

        return $out;
    }

    public static function resolve(?string $key): ?Model
    {
        [$type, $id] = array_pad(explode(':', (string) $key, 2), 2, null);
        $class = $type ? Relation::getMorphedModel($type) : null;
        if ($class === null || ! in_array($class, self::DOCUMENTS, true) || ! $id) {
            return null;
        }

        return $class::query()->find((int) $id);
    }

    /** A line's settled document from its stored columns, as the form's key. */
    public static function keyOf(array $line): ?string
    {
        return filled($line['payable_type'] ?? null) && filled($line['payable_id'] ?? null) ? $line['payable_type'].':'.$line['payable_id'] : null;
    }

    /** The stored columns from the form's key. */
    public static function split(array $line): array
    {
        $doc = self::resolve($line['payable_key'] ?? null);
        $line['payable_type'] = $doc?->getMorphClass();
        $line['payable_id'] = $doc?->getKey();
        if ($doc instanceof PaidByPayment) {
            $line['account_id'] = $doc->settlementAccountId();
        }
        unset($line['payable_key']);

        return $line;
    }

    /** @return array{model: Model, label: string, balance: int, account_id: int} */
    private static function row(Model $doc, int $balance): array
    {
        return [
            'model' => $doc,
            'label' => $doc->number.' · '.Format::date($doc->trans_date).' · '.__('open :amount', ['amount' => Format::money($balance)]),
            'balance' => $balance,
            'account_id' => $doc instanceof PaidByPayment ? $doc->settlementAccountId() : 0,
        ];
    }
}
