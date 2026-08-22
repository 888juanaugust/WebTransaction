<?php

declare(strict_types=1);

namespace App\Domain\Launch;

/**
 * One item on the launch checklist.
 *
 * Two kinds, and the distinction is the whole point of the screen.
 *
 * A **checked** item is computed from the system every time the page loads. It
 * cannot be ticked by a person, cannot go stale, and cannot be ticked
 * optimistically on a Friday afternoon. If the tax NPWP is empty, the item is
 * red — there is no argument to have about it.
 *
 * An **attested** item is one nothing inside the system can see: whether an
 * OSS registration went through, whether a lawyer read the terms, whether the
 * restore was actually practised rather than merely written. Those need a
 * person to say so, with their name against it and a date — which is a weaker
 * guarantee than a check, and the screen says so rather than making the two
 * look alike.
 */
final readonly class LaunchCheck
{
    private function __construct(
        public string $kunci,
        public string $judul,
        public string $keterangan,
        public LaunchCheckKind $jenis,
        public bool $lulus,
        /** What the system found, when that is more useful than pass/fail. */
        public ?string $temuan = null,
        /** Where to go and fix it. */
        public ?string $tindakan = null,
    ) {}

    /**
     * An item the system decides for itself.
     *
     * There is deliberately no way to construct one of these as passed when
     * the computation says otherwise.
     */
    public static function checked(
        string $kunci,
        string $judul,
        string $keterangan,
        bool $lulus,
        ?string $temuan = null,
        ?string $tindakan = null,
    ): self {
        return new self($kunci, $judul, $keterangan, LaunchCheckKind::Otomatis, $lulus, $temuan, $tindakan);
    }

    /**
     * An item only a person can confirm.
     *
     * `$lulus` comes from whether somebody has attested it, never from
     * anything the system measured — because there is nothing to measure.
     */
    public static function attested(
        string $kunci,
        string $judul,
        string $keterangan,
        bool $sudah,
        ?string $temuan = null,
    ): self {
        return new self($kunci, $judul, $keterangan, LaunchCheckKind::Pernyataan, $sudah, $temuan);
    }

    public function isAttestable(): bool
    {
        return $this->jenis === LaunchCheckKind::Pernyataan;
    }
}
