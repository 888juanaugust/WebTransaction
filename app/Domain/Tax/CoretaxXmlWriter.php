<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use DomainException;
use XMLWriter;

/**
 * The Coretax bulk-import XML: one `TaxInvoiceBulk`, one `TaxInvoice` per
 * faktur, one `GoodService` per line.
 *
 * Written to the template the accountant handed over (2026-09), element for
 * element and in its order — the two element lists below are declared as
 * data so that checking this writer against a newer template is a diff of
 * two lists, not a reading of code, and so a test can hold the template up
 * against the output.
 *
 * Three things a reviewer should look at hardest:
 *
 * - **`RefDesc` carries our invoice number.** The serial (NSFP) is not ours
 *   to choose — Coretax assigns one and hands it back beside this reference,
 *   and that is what NsfpRecorder writes onto the invoice. There is no
 *   element for the serial on the way out, which is as it should be.
 * - **`TaxBase` and `OtherTaxBase` are different numbers under code 04.**
 *   TaxBase is the selling price net of discount; OtherTaxBase is the DPP
 *   Nilai Lain, 11/12 of it, and the VAT is 12% of *that* — which is the
 *   stored per-line snapshot, not a recomputation. Under code 01 the two
 *   bases coincide, and the writer still emits the stored DPP so a rate
 *   change never rewrites a filed month.
 * - **The identifiers are sixteen and twenty-two digits.** Coretax reads the
 *   16-digit NPWP (a 15-digit one gains a leading zero) and the ID TKU is
 *   that plus a six-digit branch suffix, `000000` for a head office. See
 *   `Npwp` — every number goes through it.
 *
 * Reference codes that are Coretax's rather than ours — the buyer country,
 * the goods code, the unit codes — come from `config/pajak.php` so the
 * accountant can correct them without a code change. They are printed on the
 * filing screen for that reason.
 */
class CoretaxXmlWriter implements FakturWriter
{
    /**
     * The children of `TaxInvoice`, in the template's order.
     *
     * @var list<string>
     */
    public const ELEMEN_FAKTUR = [
        'TaxInvoiceDate', 'TaxInvoiceOpt', 'TrxCode', 'AddInfo', 'CustomDoc', 'RefDesc',
        'FacilityStamp', 'SellerIDTKU', 'BuyerTin', 'BuyerDocument', 'BuyerCountry',
        'BuyerDocumentNumber', 'BuyerName', 'BuyerAdress', 'BuyerEmail', 'BuyerIDTKU',
        'ListOfGoodService',
    ];

    /**
     * The children of `GoodService`, in the template's order.
     *
     * @var list<string>
     */
    public const ELEMEN_BARIS = [
        'Opt', 'Code', 'Name', 'Unit', 'Price', 'Qty', 'TotalDiscount', 'TaxBase',
        'OtherTaxBase', 'VATRate', 'VAT', 'STLGRate', 'STLG',
    ];

    /** An original faktur. `Pengganti` would be a replacement for a corrected one. */
    private const OPT_NORMAL = 'Normal';

    /** Goods, as opposed to `B` for services. Spare parts are goods. */
    private const OPT_BARANG = 'A';

    /** The buyer is identified by a tax number, not a NIK or a passport. */
    private const DOKUMEN_PEMBELI = 'TIN';

    public function format(): string
    {
        return 'coretax_xml';
    }

    public function extension(): string
    {
        return 'xml';
    }

    /**
     * @param  list<FakturRecord>  $fakturs
     */
    public function write(array $fakturs): string
    {
        $penjualTin = Npwp::enamBelasDigit((string) config('pajak.penjual.npwp'));

        if ($penjualTin === '') {
            throw new DomainException(
                'NPWP penjual belum diisi — lengkapi di Pengaturan perusahaan sebelum membuat file.'
            );
        }

        $penjualIdTku = Npwp::idTku($penjualTin, (string) config('pajak.penjual.id_tku'));

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'utf-8');

        $xml->startElement('TaxInvoiceBulk');
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->writeAttribute('xsi:noNamespaceSchemaLocation', 'TaxInvoice.xsd');
        $xml->writeElement('TIN', $penjualTin);

        $xml->startElement('ListOfTaxInvoice');

        foreach ($fakturs as $faktur) {
            $this->faktur($xml, $faktur, $penjualIdTku);
        }

        $xml->endElement(); // ListOfTaxInvoice
        $xml->endElement(); // TaxInvoiceBulk
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function faktur(XMLWriter $xml, FakturRecord $faktur, string $penjualIdTku): void
    {
        $nilai = [
            'TaxInvoiceDate' => $faktur->tanggalFaktur->format('Y-m-d'),
            'TaxInvoiceOpt' => self::OPT_NORMAL,
            'TrxCode' => $faktur->kodeTransaksi,
            'AddInfo' => '',
            'CustomDoc' => '',
            // Our invoice number: what comes back beside the assigned serial.
            'RefDesc' => $faktur->referensi,
            'FacilityStamp' => '',
            'SellerIDTKU' => $penjualIdTku,
            'BuyerTin' => Npwp::enamBelasDigit($faktur->npwp),
            'BuyerDocument' => self::DOKUMEN_PEMBELI,
            'BuyerCountry' => (string) config('pajak.coretax.negara_pembeli'),
            'BuyerDocumentNumber' => '',
            'BuyerName' => $this->flatten($faktur->namaWajibPajak),
            // Sic — the schema spells it with one d, and a corrected spelling
            // is an unknown element to the importer.
            'BuyerAdress' => $this->flatten($faktur->alamatPajak),
            'BuyerEmail' => trim($faktur->emailPembeli),
            'BuyerIDTKU' => $faktur->idTkuPembeli,
        ];

        $xml->startElement('TaxInvoice');

        foreach (self::ELEMEN_FAKTUR as $elemen) {
            if ($elemen === 'ListOfGoodService') {
                $xml->startElement('ListOfGoodService');

                foreach ($faktur->lines as $line) {
                    $this->baris($xml, $line);
                }

                $xml->endElement();

                continue;
            }

            $xml->writeElement($elemen, $nilai[$elemen]);
        }

        $xml->endElement(); // TaxInvoice
    }

    private function baris(XMLWriter $xml, FakturLine $line): void
    {
        $satuan = config('pajak.coretax.satuan');
        $nilai = [
            'Opt' => self::OPT_BARANG,
            'Code' => (string) config('pajak.coretax.kode_barang'),
            'Name' => $this->flatten($line->nama),
            'Unit' => (string) ($satuan[$line->satuan] ?? $satuan['PCS'] ?? ''),
            'Price' => (string) $line->hargaSatuanRupiah,
            'Qty' => (string) $line->jumlahBarang,
            'TotalDiscount' => (string) $line->diskonRupiah,
            // Selling price net of discount — what the customer was billed.
            'TaxBase' => (string) ($line->hargaTotalRupiah - $line->diskonRupiah),
            // DPP Nilai Lain, as snapshotted: 11/12 of the line under code 04.
            'OtherTaxBase' => (string) $line->dppRupiah,
            'VATRate' => (string) intdiv((int) config('pajak.ppn_rate_bps'), 100),
            'VAT' => (string) $line->ppnRupiah,
            // PPnBM: luxury goods tax. Never spare parts, but the elements are
            // part of the schema.
            'STLGRate' => '0',
            'STLG' => '0',
        ];

        $xml->startElement('GoodService');

        foreach (self::ELEMEN_BARIS as $elemen) {
            $xml->writeElement($elemen, $nilai[$elemen]);
        }

        $xml->endElement();
    }

    /**
     * Collapse whitespace so a textarea address reads as one line.
     *
     * XMLWriter escapes the characters that would break the document; this
     * is only about newlines, which are legal in XML and wrong on a faktur.
     */
    private function flatten(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
