<?php

declare(strict_types=1);

namespace App\Client\Domain\Ops\Launch;

use App\Client\Models\LaunchAttestation;
use App\Domain\Audit\Auditor;
use App\Models\User;
use RuntimeException;

/** Records and retracts a person's attestation of a readiness item: administrators only, a note required, every step audited. */
class AttestationRecorder
{
    public function __construct(private readonly LaunchReadiness $readiness) {}

    public function attest(string $key, User $actor, string $note): LaunchAttestation
    {
        $this->guard($key, $actor);
        if (trim($note) === '') {
            throw new RuntimeException(__('Write the evidence or the reference: a number, a name, a date.'));
        }
        $row = LaunchAttestation::query()->updateOrCreate(['key' => $key], ['attested_by' => $actor->id, 'attested_at' => now(), 'note' => trim($note)]);
        Auditor::log('launch_item_attested', $row, $key, ['key' => $key, 'note' => trim($note)]);
        $this->readiness->forget();

        return $row;
    }

    public function retract(string $key, User $actor, string $reason): void
    {
        $this->guard($key, $actor);
        if (trim($reason) === '') {
            throw new RuntimeException(__('Say why the attestation is withdrawn.'));
        }
        $row = LaunchAttestation::query()->where('key', $key)->first();
        if ($row === null) {
            return;
        }
        Auditor::log('launch_item_retracted', $row, $key, ['key' => $key, 'reason' => trim($reason), 'before' => ['attested_by' => $row->attested_by, 'attested_at' => $row->attested_at?->toDateTimeString(), 'note' => $row->note]]);
        $row->delete();
        $this->readiness->forget();
    }

    private function guard(string $key, User $actor): void
    {
        if (! $actor->isAdministrator()) {
            throw new RuntimeException(__('Only an administrator attests a readiness item.'));
        }
        if (! in_array($key, LaunchReadiness::ATTESTED, true)) {
            throw new RuntimeException(__('Not an item a person attests: :key', ['key' => $key]));
        }
    }
}
