<?php

declare(strict_types=1);

namespace App\Support\Legal;

use Illuminate\Support\HtmlString;

/**
 * Legal copy that appears inside the application rather than on a legal page.
 *
 * There is one sentence here and it earns its own class: the Syarat Penjualan
 * state that a buyer accepts them by placing an order, which is only true if
 * the buyer can reach them from the screen where they place it. That makes this
 * sentence part of the terms, not decoration on a form — so it lives next to
 * the rest of the legal material and is asserted by the same test file, instead
 * of being a string literal buried in a Filament action where nobody reviewing
 * the terms would ever find it.
 */
final class Terms
{
    /**
     * The line shown on the checkout dialogue.
     *
     * A link rather than a tickbox. These are standing terms for an account
     * staff already approved and that the customer has ordered under before,
     * not a click-wrap for a stranger — and a tickbox on every reorder is noise
     * a buyer learns to dismiss, which is worse than no tickbox at all.
     */
    public static function persetujuanPesanan(): HtmlString
    {
        return new HtmlString(
            'Dengan mengajukan pesanan, Anda menyetujui '
            // Coloured as well as underlined: bold alone does not read as
            // something you can click.
            .'<a href="'.route('publik.syarat').'" target="_blank" rel="noopener" '
            .'class="font-semibold underline text-primary-600 dark:text-primary-400">'
            .'Syarat Penjualan</a> yang berlaku.'
        );
    }
}
