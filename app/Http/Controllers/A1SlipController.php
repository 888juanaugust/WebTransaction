<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuKey;
use App\Domain\Company\CompanyIdentity;
use App\Domain\Payroll\Art21Slips;
use App\Models\Company\Employee;
use App\Modules\ModuleRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** An employee's A1 slip for a year, as a printable page: needs the Withholding Slips screen's view and print rights. */
class A1SlipController extends Controller
{
    public function __invoke(Request $request, Art21Slips $slips, int $employee, int $year): View
    {
        $access = app(HakAkses::class);
        abort_unless(app(ModuleRegistry::class)->menuKeyEnabled(MenuKey::WithholdingSlips)
            && $access->allows($request->user(), MenuKey::WithholdingSlips, Hak::View)
            && $access->allows($request->user(), MenuKey::WithholdingSlips, Hak::Print), 403);
        $slip = $slips->slipFor(Employee::query()->findOrFail($employee), $year);
        abort_if($slip === null, 404);

        return view('print.a1', ['slip' => $slip, 'company' => app(CompanyIdentity::class)]);
    }
}
