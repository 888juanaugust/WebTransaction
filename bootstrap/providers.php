<?php

use App\Client\ClientServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ClientServiceProvider::class, // last, so the client can override anything above
];
