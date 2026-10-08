<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\Employee;
use Carbon\CarbonImmutable;
use XMLWriter;

/**
 * The Coretax bulk-import files for Art. 21 slips, beside CoretaxXmlWriter:
 * the monthly slips of permanent employees (BPMP) and the annual A1 slips.
 * Element names and their order come from config('pajak.coretax_pph21'),
 * so a change in the tax office's template is a change of configuration.
 */
final class Pph21XmlWriter
{
    /** @param  list<array<string, mixed>>  $rows  Art21Slips::month() rows */
    public function monthly(array $rows, int $year, int $month): string
    {
        $cfg = config('pajak.coretax_pph21');
        $date = CarbonImmutable::create($year, $month, 1)->endOfMonth()->toDateString();

        return $this->document($cfg['bpmp'], array_map(fn (array $r) => $this->party($r['employee']) + [
            'month' => $month,
            'year' => $year,
            'certificate' => $cfg['certificate'],
            'object_code' => config('pajak.pph21.object_code'),
            'gross' => $r['gross'],
            'rate' => $r['rate'],
            'date' => $date,
        ], $rows));
    }

    /** @param  list<array<string, mixed>>  $slips  Art21Slips::year() slips */
    public function annual(array $slips): string
    {
        $cfg = config('pajak.coretax_pph21');

        return $this->document($cfg['a1'], array_map(fn (array $s) => $this->party($s['employee']) + [
            'second_employer' => 'No',
            'month_start' => $s['month_start'],
            'month_end' => $s['month_end'],
            'year' => $s['year'],
            'object_code' => config('pajak.pph21.object_code'),
            'months' => $s['annual']['months'],
            'salary' => $s['rows']['salary'],
            'gross_up' => 'No',
            'tax_allowance' => $s['rows']['tax_allowance'],
            'other_allowance' => $s['rows']['other_allowance'],
            'honorarium' => $s['rows']['honorarium'],
            'insurance' => $s['rows']['insurance'],
            'in_kind' => $s['rows']['in_kind'],
            'bonus' => $s['rows']['bonus'],
            'pension' => $s['pension'],
            'zakat' => $s['zakat'],
            'previous_slip' => '',
            'certificate' => $cfg['certificate'],
            'tax' => $s['tax_due'],
            'date' => $s['date'],
        ], $slips));
    }

    /** @return array<string, string> who the slip is for, and the employer's place of business */
    private function party(Employee $employee): array
    {
        $cfg = config('pajak.coretax_pph21');
        $npwp = preg_replace('/\D/', '', (string) $employee->npwp_no) ?? '';
        $nik = preg_replace('/\D/', '', (string) $employee->nik_no) ?? '';
        $foreign = filled($employee->nationality) && ! in_array(mb_strtolower(trim((string) $employee->nationality)), ['indonesia', 'indonesian', 'wni', 'idn'], true);

        return [
            'counterpart_opt' => $foreign ? $cfg['counterpart_foreign'] : $cfg['counterpart_resident'],
            'passport' => '',
            'tin' => strlen($npwp) === 16 ? $npwp : ($nik !== '' ? $nik : $npwp),
            'ptkp' => (string) $employee->tax_status?->value,
            'position' => mb_substr((string) $employee->position, 0, 50),
            'idtku' => $this->idTku(),
        ];
    }

    private function idTku(): string
    {
        $prefs = app(Preferensi::class);
        $tin = preg_replace('/\D/', '', (string) $prefs->get(PreferensiKey::CompanyNpwp)) ?? '';

        return (string) ($prefs->get(PreferensiKey::Nitku) ?: $tin.config('pajak.coretax.idtku_suffix'));
    }

    /** @param  array{root: string, list: string, record: string, fields: array<string, string>}  $shape */
    private function document(array $shape, array $records): string
    {
        $prefs = app(Preferensi::class);
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'utf-8');
        $xml->startElement($shape['root']);
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->writeElement('TIN', preg_replace('/\D/', '', (string) $prefs->get(PreferensiKey::CompanyNpwp)) ?? '');
        $xml->startElement($shape['list']);
        foreach ($records as $record) {
            $xml->startElement($shape['record']);
            foreach ($shape['fields'] as $element => $key) {
                $xml->writeElement($element, (string) ($record[$key] ?? ''));
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
