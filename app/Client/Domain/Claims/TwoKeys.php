<?php

declare(strict_types=1);

namespace App\Client\Domain\Claims;

use App\Client\Models\Claim;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\ScreenKey;
use App\Domain\Audit\Auditor;
use App\Models\User;
use RuntimeException;

/**
 * Two keys, two people: whoever files a claim never verifies it, the Owner
 * included. The verifier holds the Update right on the claim's screen and
 * decides a filed claim once, with a note when rejecting.
 */
final class TwoKeys
{
    public function __construct(private readonly HakAkses $access) {}

    public function mayDecide(Claim $claim, ?User $actor, ScreenKey $screen): bool
    {
        return $actor !== null && $claim->isFiled()
            && $this->access->allows($actor, $screen, Hak::Update)
            && (int) $claim->filed_by !== (int) $actor->id;
    }

    public function assertMayDecide(Claim $claim, ?User $actor, ScreenKey $screen): void
    {
        if ($actor === null || ! $claim->isFiled()) {
            throw new RuntimeException(__('This claim has been decided already.'));
        }
        if (! $this->access->allows($actor, $screen, Hak::Update)) {
            throw new RuntimeException(__('Only a user with the Update right on :screen verifies a claim.', ['screen' => $screen->label()]));
        }
        if ((int) $claim->filed_by === (int) $actor->id) {
            throw new RuntimeException(__('Whoever files a claim never verifies it: two keys, two people.'));
        }
    }

    public function reject(Claim $claim, User $actor, ScreenKey $screen, string $note): void
    {
        $this->assertMayDecide($claim, $actor, $screen);
        if (trim($note) === '') {
            throw new RuntimeException(__('A rejection needs a note.'));
        }
        $claim->forceFill(['status' => ClaimStatus::REJECTED, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => trim($note)])->saveQuietly();
        Auditor::log('claim_rejected', $claim, null, ['note' => trim($note)]);
    }
}
