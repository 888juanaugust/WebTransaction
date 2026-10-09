<?php

declare(strict_types=1);

use App\Client\Site\Http\LanguageController;
use App\Client\Site\Http\RobotsController;
use App\Client\Site\Http\SiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The public site
|--------------------------------------------------------------------------
|
| Open and indexed, under Indonesian slugs. Loaded by ClientServiceProvider
| after the base's routes, so "/" is the site and no longer a redirect to
| the panel. Controllers, never closures: the routes are cached in production.
|
*/

Route::get('/', [SiteController::class, 'home'])->name('site.home');
Route::get('/tentang-kami', [SiteController::class, 'about'])->name('site.about');
Route::get('/mitra', [SiteController::class, 'partners'])->name('site.partners');
Route::get('/rencana-pengembangan', [SiteController::class, 'roadmap'])->name('site.roadmap');
Route::get('/kontak', [SiteController::class, 'contact'])->name('site.contact');
Route::get('/kebijakan-privasi', [SiteController::class, 'privacy'])->name('site.privacy');
Route::get('/syarat-penjualan', [SiteController::class, 'terms'])->name('site.terms');
Route::get('/masuk', [SiteController::class, 'signIn'])->name('site.sign_in');
Route::get('/bahasa/{code}', LanguageController::class)->name('site.language');
Route::get('/robots.txt', [RobotsController::class, 'robots'])->name('site.robots');
Route::get('/sitemap.xml', [RobotsController::class, 'sitemap'])->name('site.sitemap');
