<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Sales\SalesInvoice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use XMLWriter;

/**
 * The tax office's bulk-import file (TaxInvoiceBulk): one TaxInvoice per
 * sales invoice, one GoodService per line, with the tax base and the "other
 * tax base" (11/12 of it) the 12 % rate is charged on. No API: a person
 * uploads the file and pastes the serial numbers back.
 */
final class CoretaxXmlWriter
{
    /** @param  Collection<int, SalesInvoice>  $invoices */
    public function write(Collection $invoices): string
    {
        $cfg = config('pajak.coretax');
        $prefs = app(Preferensi::class);
        $sellerTin = preg_replace('/\D/', '', (string) $prefs->get(PreferensiKey::CompanyNpwp)) ?? '';
        $sellerIdTku = (string) ($prefs->get(PreferensiKey::Nitku) ?: $sellerTin.$cfg['idtku_suffix']);

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'utf-8');
        $xml->startElement('TaxInvoiceBulk');
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->writeAttribute('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $xml->writeElement('TIN', $sellerTin);
        $xml->startElement('ListOfTaxInvoice');

        foreach ($invoices as $invoice) {
            $buyer = TaxParty::fromParty($invoice->customer);
            $usesOtherBase = $invoice->lines->contains(fn ($line) => $line->taxCode && (int) $line->taxCode->dpp_denominator !== (int) $line->taxCode->dpp_numerator);

            $xml->startElement('TaxInvoice');
            $xml->writeElement('TaxInvoiceDate', CarbonImmutable::parse($invoice->trans_date)->toDateString());
            $xml->writeElement('TaxInvoiceOpt', $cfg['tax_invoice_opt']);
            $xml->writeElement('TrxCode', $usesOtherBase ? $cfg['trx_code_dpp_lain'] : $cfg['trx_code_normal']);
            $xml->writeElement('AddInfo', '');
            $xml->writeElement('CustomDoc', '');
            $xml->writeElement('CustomDocMonthYear', '');
            $xml->writeElement('RefDesc', $invoice->number);
            $xml->writeElement('FacilityStamp', '');
            $xml->writeElement('SellerIDTKU', $sellerIdTku);
            $xml->writeElement('BuyerTin', $buyer->idNumber);
            $xml->writeElement('BuyerDocument', $cfg['buyer_document'][$buyer->idType] ?? $cfg['buyer_document']['other']);
            $xml->writeElement('BuyerCountry', $cfg['buyer_country']);
            $xml->writeElement('BuyerDocumentNumber', $buyer->idType === 'npwp' ? '' : $buyer->idNumber);
            $xml->writeElement('BuyerName', $buyer->name);
            $xml->writeElement('BuyerAdress', $buyer->address);
            $xml->writeElement('BuyerEmail', (string) $buyer->email);
            $xml->writeElement('BuyerIDTKU', $buyer->idTku);
            $xml->startElement('ListOfGoodService');
            foreach ($invoice->lines as $line) {
                $item = $line->item;
                $isService = $item && method_exists($item, 'isService') && $item->isService();
                $gross = (int) $line->amount + (int) $line->discount_amount;
                $xml->startElement('GoodService');
                $xml->writeElement('Opt', $isService ? 'B' : 'A');
                $xml->writeElement('Code', (string) ($item?->item_tax_code ?: ($isService ? $cfg['service_code'] : $cfg['goods_code'])));
                $xml->writeElement('Name', (string) ($item?->name ?? ''));
                $xml->writeElement('Unit', (string) ($line->unit?->unit_tax_code ?: $cfg['unit_code']));
                $xml->writeElement('Price', $this->decimal($line->unit_price));
                $xml->writeElement('Qty', $this->decimal($line->quantity));
                $xml->writeElement('TotalDiscount', (string) (int) $line->discount_amount);
                $xml->writeElement('TaxBase', (string) (int) $line->amount);
                $xml->writeElement('OtherTaxBase', (string) (int) $line->dpp_amount);
                $xml->writeElement('VATRate', (string) $cfg['vat_rate']);
                $xml->writeElement('VAT', (string) (int) $line->tax_amount);
                $xml->writeElement('STLGRate', '0');
                $xml->writeElement('STLG', '0');
                $xml->endElement();
                unset($gross);
            }
            $xml->endElement(); // ListOfGoodService
            $xml->endElement(); // TaxInvoice
        }

        $xml->endElement(); // ListOfTaxInvoice
        $xml->endElement(); // TaxInvoiceBulk
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function decimal(string|int|float|null $value): string
    {
        // On the decimal string, never a float: a unit price keeps every digit it was stored with.
        return (string) BigDecimal::of(trim((string) ($value ?? '')) === '' ? '0' : (string) $value)->toScale(4, RoundingMode::HalfUp)->strippedOfTrailingZeros();
    }
}
