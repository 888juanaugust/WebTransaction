<?php

declare(strict_types=1);

namespace App\Domain\Launch;

use App\Domain\Audit\AuditLogger;
use App\Models\LaunchAttestation;
use App\Models\User;
use DomainException;

/**
 * Recording, and withdrawing, somebody's word.
 *
 * Behind the audit-log permission, which is Owner-only. Deciding that the
 * business is legally cleared to trade online is not a clerical act, and it is
 * the same person who would answer for it if it turned out not to be true.
 */
class AttestationRecorder
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function attest(string $kunci, User $actor, ?string $catatan = null): LaunchAttestation
    {
        $this->assertMayAttest($actor);
        $this->assertAttestable($kunci);

        /*
         * Re-attesting refreshes the date and the note rather than being
         * refused. A lawyer re-reads the terms after a change; a registration
         * is renewed. Refusing would push people into retract-then-attest,
         * which loses the original date for no gain.
         */
        $attestation = LaunchAttestation::query()->updateOrCreate(
            ['kunci' => $kunci],
            ['attested_by' => $actor->id, 'attested_at' => now(), 'catatan' => $catatan],
        );

        /*
         * The checklist is memoised per request, and this very request is
         * about to re-render it. Without this the owner attests an item and
         * watches it stay red.
         */
        app(LaunchReadiness::class)->forget();

        $this->audit->log(
            action: 'launch_item_attested',
            subject: $attestation,
            newValue: ['kunci' => $kunci, 'catatan' => $catatan],
            actor: $actor,
            alasan: $catatan,
        );

        return $attestation->refresh();
    }

    /** Take it back. The audit log keeps the fact that it was once claimed. */
    public function retract(string $kunci, User $actor, string $alasan): void
    {
        $this->assertMayAttest($actor);

        $attestation = LaunchAttestation::query()->where('kunci', $kunci)->first();

        if ($attestation === null) {
            throw new DomainException('Belum pernah dinyatakan, jadi tidak ada yang dicabut.');
        }

        if (trim($alasan) === '') {
            throw new DomainException('Pencabutan pernyataan harus menyebutkan alasannya.');
        }

        $this->audit->log(
            action: 'launch_item_retracted',
            subject: $attestation,
            oldValue: [
                'kunci' => $kunci,
                'attested_at' => $attestation->attested_at?->toDateString(),
                'catatan' => $attestation->catatan,
            ],
            actor: $actor,
            alasan: trim($alasan),
        );

        $attestation->delete();

        app(LaunchReadiness::class)->forget();
    }

    /**
     * Only the items that genuinely cannot be checked.
     *
     * Without this, an unknown key would create an attestation that no
     * checklist item reads — a tick nobody sees, sitting in the table forever
     * looking like the work was done.
     */
    private function assertAttestable(string $kunci): void
    {
        $attestable = array_map(
            fn (LaunchCheck $c) => $c->kunci,
            array_filter(
                app(LaunchReadiness::class)->checks(),
                fn (LaunchCheck $c) => $c->isAttestable(),
            ),
        );

        if (! in_array($kunci, $attestable, true)) {
            throw new DomainException(
                "'{$kunci}' bukan item yang bisa dinyatakan — sistem memeriksanya sendiri."
            );
        }
    }

    private function assertMayAttest(User $actor): void
    {
        if (! $actor->role()->canViewAuditLog()) {
            throw new DomainException('Hanya Pemilik yang boleh menyatakan kesiapan peluncuran.');
        }
    }
}
