<?php

declare(strict_types=1);

namespace App\Modules;

use App\Domain\Access\MenuRegistry;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Fulfilment\FulfilmentService;
use App\Domain\Posting\DocumentGuard;
use App\Domain\Posting\PostingService;
use Illuminate\Contracts\Foundation\Application;

/** What a module may wire into when it boots. */
final class ModuleContext
{
    public function __construct(
        public readonly Application $app,
        public readonly PostingService $postings,
        public readonly DocumentGuard $guard,
        public readonly FulfilmentService $fulfilment,
        public readonly MenuRegistry $menus,
        public readonly ApprovalEngine $approvals,
    ) {}
}
