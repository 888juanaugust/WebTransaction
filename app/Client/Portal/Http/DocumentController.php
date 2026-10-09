<?php

declare(strict_types=1);

namespace App\Client\Portal\Http;

use App\Client\Portal\Portal;
use App\Client\Portal\PortalActor;
use App\Client\Portal\PortalDocuments;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Printing\PdfRenderer;
use App\Domain\Printing\Printable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A buyer's document as a PDF: only their own customer's, only when approved,
 * rendered in the Portal user's name. A guessed id of another customer's
 * document is not found, as if it did not exist.
 */
class DocumentController
{
    public function __invoke(Request $request, string $alias, int $id): Response
    {
        $meta = in_array($alias, PortalDocuments::ALIASES, true) ? Printable::for($alias) : null;
        if ($meta === null) {
            throw new NotFoundHttpException;
        }
        $document = $meta['model']::query()->find($id);
        if ($document === null || (int) $document->getAttribute('customer_id') !== (int) Portal::customer()->id) {
            throw new NotFoundHttpException;
        }
        if (! app(ApprovalEngine::class)->isApproved($document)) {
            throw new HttpException(403, __(':number is not approved yet.', ['number' => $document->getAttribute('number')]));
        }

        try {
            $pdf = PortalActor::run(fn ($portal) => app(PdfRenderer::class)->render($alias, $id, $portal));
        } catch (RuntimeException $e) {
            throw new HttpException(403, $e->getMessage());
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $document->getAttribute('number')) ?: 'document';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
        ]);
    }
}
