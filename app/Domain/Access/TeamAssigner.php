<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Audit\AuditLogger;
use App\Models\Company;
use App\Models\User;
use RuntimeException;

/**
 * Puts a customer in the charge of one sales and one marketing.
 *
 * The pair is the organisation's unit of responsibility: the sales visits and
 * sells, the marketing approves the credit and answers for the debt. Both
 * halves of that only work when the assignment is real, so this refuses the
 * assignments that would be fictions:
 *
 * - somebody whose role is not the seat being filled — a Finance clerk named
 *   as the marketing of record would hold approval rights their role denies;
 * - somebody from another region — they cannot even *see* the customer, so
 *   every pending order would wait on a person to whom it is invisible;
 * - somebody deactivated — a leaver as approver is a queue nobody empties.
 *
 * Audited, because "who approved this customer's credit" traces back through
 * "who was their marketing at the time", and that answer has to be written
 * down when it changes, not reconstructed from memory.
 */
class TeamAssigner
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function assignSales(Company $company, ?User $sales, ?User $actor = null): void
    {
        $this->assign($company, $sales, Role::Sales, 'sales_user_id', 'company_sales_assigned', $actor);
    }

    public function assignMarketing(Company $company, ?User $marketing, ?User $actor = null): void
    {
        $this->assign($company, $marketing, Role::Marketing, 'marketing_user_id', 'company_marketing_assigned', $actor);
    }

    private function assign(
        Company $company,
        ?User $anggota,
        Role $seat,
        string $column,
        string $action,
        ?User $actor,
    ): void {
        $lama = $company->getAttribute($column);
        $baru = $anggota?->getKey();

        if ((int) $lama === (int) $baru && ($lama === null) === ($baru === null)) {
            return;
        }

        if ($anggota !== null) {
            $this->refuseMismatch($company, $anggota, $seat);
        }

        $company->forceFill([$column => $baru])->save();

        $this->audit->log(
            action: $action,
            subject: $company,
            oldValue: [$column => $lama === null ? null : (int) $lama],
            newValue: [$column => $baru, 'nama' => $anggota?->name],
            actor: $actor,
        );
    }

    private function refuseMismatch(Company $company, User $anggota, Role $seat): void
    {
        if ($anggota->role !== $seat) {
            throw new RuntimeException(sprintf(
                '%s berperan %s, bukan %s — kursi ini butuh peran %s.',
                $anggota->name,
                $anggota->role->label(),
                $seat->label(),
                $seat->label(),
            ));
        }

        if (! $anggota->is_active) {
            throw new RuntimeException(
                "{$anggota->name} sudah nonaktif. Orang yang tidak bisa masuk tidak bisa menjadi penanggung jawab."
            );
        }

        /*
         * Same region, or the assignment is a fiction: the scope keeps this
         * customer's rows invisible to them, so every pending order would sit
         * waiting on somebody who cannot see it exists.
         */
        if ((int) $anggota->region_id !== (int) $company->region_id) {
            throw new RuntimeException(
                "{$anggota->name} bekerja di wilayah lain dan tidak bisa melihat pelanggan ini. "
                .'Pindahkan wilayahnya dulu, atau pilih orang dari wilayah pelanggan.'
            );
        }
    }
}
