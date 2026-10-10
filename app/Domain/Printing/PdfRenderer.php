<?php

declare(strict_types=1);

namespace App\Domain\Printing;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * A document's print as a PDF: the same view the print screen shows, with a
 * few rules for the PDF engine only (it lays out tables, not flex boxes).
 * Rendering does not mark the document printed.
 */
final class PdfRenderer
{
    public function __construct(private readonly PrintJob $job) {}

    public function render(string $alias, int $id, User $user): string
    {
        $print = $this->job->data($alias, $id, $user);
        $paper = $print['layout']['paper'] ?? 'A4';
        $template = (string) ($print['layout']['template'] ?? '');

        return Pdf::loadView($template !== '' && view()->exists($template) ? $template : 'print.document', $print + ['pdf' => true])
            ->setPaper(in_array($paper, ['A4', 'A5', 'Letter', 'Legal'], true) ? strtolower($paper) : 'a4', ($print['layout']['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait')
            ->output();
    }
}
