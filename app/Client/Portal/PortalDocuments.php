<?php

declare(strict_types=1);

namespace App\Client\Portal;

use App\Domain\Printing\Printable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/** The buyer's documents as PDF: the order, the surat jalan (the delivery's print) and the invoice, from signed, short-lived links. */
final class PortalDocuments
{
    public const ALIASES = ['sales_order', 'delivery', 'sales_invoice'];

    public static function url(Model $document): ?string
    {
        $alias = Printable::aliasOf($document);
        if ($alias === null || ! in_array($alias, self::ALIASES, true)) {
            return null;
        }

        return URL::temporarySignedRoute('filament.portal.document', now()->addMinutes(30), ['alias' => $alias, 'id' => $document->getKey()]);
    }
}
