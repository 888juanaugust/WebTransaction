<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * The shapes a tax number takes, in one place.
 *
 * People type an NPWP the way the card shows it — `01.234.567.8-901.000` —
 * and both the old 15-digit and the current 16-digit forms are in
 * circulation since the 2024 change. Coretax wants sixteen bare digits, and
 * for the place of business it wants the NITKU / ID TKU: those sixteen plus
 * a six-digit branch suffix, `000000` for the head office. Every writer and
 * importer that touches a tax number goes through here rather than carrying
 * its own regex, because two regexes that disagree report a sale against
 * somebody else's number.
 */
final class Npwp
{
    /** The head-office suffix that turns a 16-digit NPWP into an ID TKU. */
    public const KANTOR_PUSAT = '000000';

    /** Digits only, whichever way it was typed. */
    public static function digits(?string $npwp): string
    {
        return preg_replace('/\D+/', '', (string) $npwp) ?? '';
    }

    /**
     * The 16-digit form Coretax reads.
     *
     * A 15-digit number is the old form of the same number and becomes the
     * new one with a leading zero — that is the published conversion, not a
     * guess. Anything else is returned as its digits, for the blocker to
     * refuse by length rather than for this to invent a number.
     */
    public static function enamBelasDigit(?string $npwp): string
    {
        $digits = self::digits($npwp);

        return strlen($digits) === 15 ? '0'.$digits : $digits;
    }

    /**
     * The ID TKU (NITKU) for a place of business.
     *
     * An explicit one wins — a buyer with a registered branch has a suffix
     * other than `000000`, and only they know it. Without one, the head
     * office of the NPWP is the correct default, and an empty NPWP gives an
     * empty ID TKU rather than six zeros pretending to be somebody.
     */
    public static function idTku(?string $npwp, ?string $explicit = null): string
    {
        $tku = self::digits($explicit);

        if ($tku !== '') {
            return $tku;
        }

        $enamBelas = self::enamBelasDigit($npwp);

        return $enamBelas === '' ? '' : $enamBelas.self::KANTOR_PUSAT;
    }

    /** Does this look like an ID TKU somebody typed on purpose? */
    public static function looksLikeIdTku(?string $tku): bool
    {
        return strlen(self::digits($tku)) === 22;
    }
}
