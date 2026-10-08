<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Printing\PrintJob;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** The printable page of a document: plain HTML sized to the layout's paper, printed by the browser. */
class PrintController extends Controller
{
    public function __invoke(Request $request, PrintJob $job, string $alias, int $id): View
    {
        try {
            $print = $job->prepare($alias, $id, $request->user(), $request->integer('layout') ?: null);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new HttpException(403, $e->getMessage());
        }

        return view('print.document', $print);
    }
}
