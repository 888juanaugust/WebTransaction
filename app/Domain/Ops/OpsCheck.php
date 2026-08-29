<?php

declare(strict_types=1);

namespace App\Domain\Ops;

final readonly class OpsCheck
{
    public function __construct(
        public string $kunci,
        public string $judul,
        public OpsStatus $status,
        /** What was measured, in words a 03:00 reader can act on. */
        public string $temuan,
    ) {}

    public static function sehat(string $kunci, string $judul, string $temuan): self
    {
        return new self($kunci, $judul, OpsStatus::Sehat, $temuan);
    }

    public static function waspada(string $kunci, string $judul, string $temuan): self
    {
        return new self($kunci, $judul, OpsStatus::Waspada, $temuan);
    }

    public static function gawat(string $kunci, string $judul, string $temuan): self
    {
        return new self($kunci, $judul, OpsStatus::Gawat, $temuan);
    }
}
