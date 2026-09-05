<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tax\FakturExporter;
use App\Domain\Tax\FilingScope;
use App\Models\FakturExport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handing back an export file exactly as it was written.
 *
 * Read from disk rather than regenerated. Somebody will need to produce the
 * file that was actually uploaded — during an audit, or when the tax office
 * queries a figure — and regenerating it then would use whatever the code does
 * by then, which is a different file that merely resembles the one filed.
 */
class FakturExportController extends Controller
{
    /**
     * Resolved here rather than by route-model binding, because binding runs
     * the model's global scopes and a filing belongs to the company rather
     * than to one region's books — see `FilingScope`. Bound, the download
     * beside a filing made from another region 404'd, on the one file that
     * has to be producible during an audit.
     */
    public function __invoke(string $fakturExport, FakturExporter $exporter): StreamedResponse
    {
        // Named guard, matching the route — `auth()` alone means "the default
        // guard", and this application has two. Same rule as every other
        // document controller.
        abort_unless(auth('web')->user()?->role()->canExportFaktur() ?? false, 403);

        $export = FilingScope::entityWide(FakturExport::class)->find($fakturExport);

        abort_if($export === null, 404);

        $contents = $exporter->contents($export);

        abort_if($contents === null, 404, 'File ekspor sudah tidak ada di penyimpanan.');

        $name = $export->nomor.'.'.pathinfo((string) $export->file_path, PATHINFO_EXTENSION);

        return response()->streamDownload(fn () => print $contents, $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
