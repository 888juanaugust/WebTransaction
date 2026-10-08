<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Access\BranchLimit;
use App\Domain\Audit\Auditor;
use App\Domain\Printing\PdfRenderer;
use App\Domain\Shared\Locales;
use App\Jobs\SendTaxInvoiceMail;
use App\Mail\TaxInvoiceMessage;
use App\Models\Sales\SalesInvoice;
use App\Models\Tax\TaxInvoiceMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Emailing a customer its tax invoice once the serial is back: the Coretax
 * PDF (uploaded, matched to the invoice by its serial) and the company's own
 * invoice as a PDF. A send is queued as a row of the append-only mail log
 * and answered by another (sent, failed or skipped); an invoice already sent
 * under its serial is not sent again unless asked.
 */
final class TaxInvoiceMailer
{
    public const PDF_FOLDER = 'tax-invoices/coretax';

    /** Where the upload fields put new PDFs; nothing outside it is ever taken as an upload. */
    public const UPLOAD_FOLDER = 'tax-invoices/uploads';

    public function __construct(private readonly PdfRenderer $pdf) {}

    public function recipient(SalesInvoice $invoice): ?string
    {
        $customer = $invoice->customer;

        return ($customer?->tax_invoice_email ?: $customer?->email) ?: null;
    }

    /** not_sent | queued | sent | failed, for the invoice's current serial. */
    public function status(SalesInvoice $invoice): string
    {
        $last = TaxInvoiceMail::query()->where('sales_invoice_id', $invoice->id)->where('serial', (string) $invoice->nsfp)
            ->where('status', '!=', TaxInvoiceMail::SKIPPED)->latest('id')->first();

        return $last?->status ?? 'not_sent';
    }

    public function wasSent(SalesInvoice $invoice): bool
    {
        return TaxInvoiceMail::query()->where('sales_invoice_id', $invoice->id)->where('serial', (string) $invoice->nsfp)->where('status', TaxInvoiceMail::SENT)->exists();
    }

    /** Queues the email; refuses what cannot be sent, and an invoice already sent under its serial unless $again. */
    public function queue(SalesInvoice $invoice, bool $again = false): TaxInvoiceMail
    {
        $serial = trim((string) $invoice->nsfp);
        if ($serial === '') {
            throw new RuntimeException(__(':number has no tax invoice serial yet.', ['number' => $invoice->number]));
        }
        if (blank($invoice->coretax_pdf_path) || ! Storage::disk('local')->exists($invoice->coretax_pdf_path)) {
            throw new RuntimeException(__(':number has no Coretax PDF; upload it first.', ['number' => $invoice->number]));
        }
        $to = $this->recipient($invoice) ?? throw new RuntimeException(__(':customer has no email address for tax invoices.', ['customer' => $invoice->customer?->name]));
        if (! $again && $this->wasSent($invoice)) {
            throw new RuntimeException(__(':number was already sent; use "Send again" to repeat it.', ['number' => $invoice->number]));
        }
        $request = TaxInvoiceMail::query()->create([
            'sales_invoice_id' => $invoice->id,
            'serial' => $serial,
            'recipient' => $to,
            'attachments' => [TaxInvoiceMessage::fileName('tax-invoice-'.$serial), TaxInvoiceMessage::fileName($invoice->number)],
            'status' => TaxInvoiceMail::QUEUED,
            'resend' => $again,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);
        SendTaxInvoiceMail::dispatch($request->id)->afterCommit();

        return $request;
    }

    /** The queued send, done once: a second run finds it answered and does nothing. */
    public function deliver(int $requestId): void
    {
        DB::transaction(function () use ($requestId): void {
            $request = TaxInvoiceMail::query()->whereKey($requestId)->where('status', TaxInvoiceMail::QUEUED)->lockForUpdate()->first();
            if ($request === null || TaxInvoiceMail::query()->where('request_id', $requestId)->exists()) {
                return;
            }
            $invoice = $request->invoice;
            $answer = fn (string $status, ?string $error = null) => TaxInvoiceMail::query()->create([
                'sales_invoice_id' => $request->sales_invoice_id, 'serial' => $request->serial, 'recipient' => $request->recipient,
                'attachments' => $request->attachments, 'status' => $status, 'error' => $error, 'resend' => $request->resend,
                'request_id' => $request->id, 'user_id' => $request->user_id, 'created_at' => now(),
            ]);
            if (! $request->resend && $this->wasSent($invoice)) {
                $answer(TaxInvoiceMail::SKIPPED, __('Already sent under this serial.'));

                return;
            }
            try {
                $user = $request->user ?? throw new RuntimeException(__('Nobody to render the invoice as.'));
                // A document to a customer goes in the company's language, whoever queued it.
                Locales::using(Locales::companyDefault(), function () use ($invoice, $user, $request): void {
                    $pdf = $this->pdf->render('sales_invoice', $invoice->id, $user);
                    Mail::to($request->recipient)->send(new TaxInvoiceMessage($invoice, $request->serial, (string) $invoice->coretax_pdf_path, $pdf));
                });
            } catch (Throwable $e) {
                $answer(TaxInvoiceMail::FAILED, mb_substr($e->getMessage(), 0, 2000));

                return;
            }
            $answer(TaxInvoiceMail::SENT);
            Auditor::log('tax_invoice_emailed', $invoice, (string) $invoice->number, ['serial' => $request->serial, 'to' => $request->recipient]);
        });
    }

    /**
     * Matches uploaded Coretax PDFs to invoices by the serial in the file's name (or, failing that, its text) and
     * keeps each with its invoice.
     *
     * @param  array<string, string>  $files  original name → stored path (on the local disk)
     * @return array{matched: array<string, string>, unmatched: list<string>} original name → invoice number; names not matched
     */
    public function attachMany(array $files): array
    {
        // Only invoices in the user's branches: a PDF never lands on another branch's invoice.
        $invoices = BranchLimit::apply(SalesInvoice::query(), auth()->user())->whereNotNull('nsfp')->where('nsfp', '!=', '')->get(['id', 'number', 'nsfp', 'coretax_pdf_path']);
        $bySerial = $invoices->keyBy(fn (SalesInvoice $i) => self::digits((string) $i->nsfp));
        $matched = [];
        $unmatched = [];
        foreach ($files as $name => $path) {
            self::assertUpload($path);
            $invoice = $this->match($name, (string) Storage::disk('local')->get($path), $bySerial->all());
            if ($invoice === null) {
                $unmatched[] = $name;
                Storage::disk('local')->delete($path);

                continue;
            }
            $this->attach($invoice, $path);
            $matched[$name] = $invoice->number;
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /** Keeps an uploaded PDF as the invoice's Coretax PDF (moved into the invoices' folder). */
    public function attach(SalesInvoice $invoice, string $uploadedPath): void
    {
        self::assertUpload($uploadedPath);
        $target = self::PDF_FOLDER.'/'.$invoice->id.'-'.self::digits((string) $invoice->nsfp).'.pdf';
        if ($uploadedPath !== $target) {
            Storage::disk('local')->delete($target);
            Storage::disk('local')->move($uploadedPath, $target);
        }
        $invoice->forceFill(['coretax_pdf_path' => $target])->saveQuietly();
        Auditor::log('coretax_pdf_attached', $invoice, (string) $invoice->number, ['serial' => $invoice->nsfp]);
    }

    /** @param  array<string, SalesInvoice>  $bySerial */
    private function match(string $name, string $content, array $bySerial): ?SalesInvoice
    {
        preg_match_all('/\d[\d.\-]{11,}\d/', $name, $inName);
        preg_match_all('/\d{13,17}/', $content, $inText);
        foreach ([...$inName[0], ...$inText[0]] as $candidate) {
            $digits = self::digits($candidate);
            if (isset($bySerial[$digits])) {
                return $bySerial[$digits];
            }
        }

        return null;
    }

    private static function assertUpload(string $path): void
    {
        if (! str_starts_with($path, self::UPLOAD_FOLDER.'/') || str_contains($path, '..')) {
            throw new RuntimeException(__('Only a PDF uploaded here can be attached.'));
        }
    }

    private static function digits(string $text): string
    {
        return preg_replace('/\D/', '', $text) ?? '';
    }
}
