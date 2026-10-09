<?php

declare(strict_types=1);

namespace App\Client\Site\Http;

use App\Client\Models\SiteImage;
use App\Client\Site\Copy;
use App\Client\Site\Legal;
use App\Domain\Shared\Locales;
use App\Models\Company\Branch;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/** The public pages. Open, indexed, bilingual; no price anywhere. */
class SiteController
{
    public function home(): View
    {
        return view('client.site.home', [
            'promos' => SiteImage::query()->live(SiteImage::PROMO)->get(),
            'photos' => SiteImage::query()->live(SiteImage::PHOTO)->get(),
            'categories' => Copy::records('categories'),
            'partners' => array_slice(Copy::records('partners'), 0, 3),
            'branches' => $this->branches(),
        ]);
    }

    public function about(): View
    {
        return view('client.site.about');
    }

    public function partners(): View
    {
        return view('client.site.partners', ['partners' => Copy::records('partners')]);
    }

    public function roadmap(): View
    {
        return view('client.site.roadmap', ['items' => Copy::records('roadmap')]);
    }

    public function contact(): View
    {
        return view('client.site.contact', ['branches' => $this->branches()]);
    }

    /** An instrument under Indonesian law: the whole page in Bahasa Indonesia, whatever the visitor chose. */
    public function privacy(): Response
    {
        return $this->legal(fn (Legal $legal) => $legal->privacy(), 'site.privacy');
    }

    public function terms(): Response
    {
        return $this->legal(fn (Legal $legal) => $legal->terms(), 'site.terms');
    }

    /** Rendered inside the Indonesian locale, chrome included: a view object would render later, after the locale came back. */
    private function legal(callable $page, string $route): Response
    {
        return Locales::using('id', fn () => response(view('client.site.legal', ['page' => $page(app(Legal::class)), 'route' => $route])->render()));
    }

    /** Two doors: staff and buyers sign in on different guards against different tables. */
    public function signIn(): View
    {
        return view('client.site.sign-in', [
            'portalLogin' => Filament::getPanel('portal')->getLoginUrl(),
            'adminLogin' => Filament::getPanel('admin')->getLoginUrl(),
        ]);
    }

    /** @return Collection<int, Branch> */
    private function branches(): Collection
    {
        return Branch::query()->where('is_active', true)->orderBy('code')->orderBy('name')->get();
    }
}
