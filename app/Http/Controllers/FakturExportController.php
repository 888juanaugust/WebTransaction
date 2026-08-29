<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tax\FakturExporter;
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
    public function __invoke(FakturExport $fakturExport, FakturExporter $exporter): StreamedResponse
    {
        // Named guard, matching the route — `auth()` alone means "the default
        // guard", and this application has two. Same rule as every other
        // document controller.
        abort_unless(auth('web')->user()?->role()->canExportFaktur() ?? false, 403);

        $contents = $exporter->contents($fakturExport);

        abort_if($contents === null, 404, 'File ekspor sudah tidak ada di penyimpanan.');

        $name = $fakturExport->nomor.'.'.pathinfo((string) $fakturExport->file_path, PATHINFO_EXTENSION);

        return response()->streamDownload(fn () => print $contents, $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
