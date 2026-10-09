@extends('client.site.layout')

{{-- A legal instrument under Indonesian law: the whole page in Bahasa Indonesia, from App\Client\Site\Legal. --}}

@section('title', $page['title'])
@section('description', $page['lede'])

@section('content')

    <section class="site-page-hero site-page-hero--legal">
        <h1 class="site-page-hero__title">{{ $page['title'] }}</h1>
        <p class="site-page-hero__lede">{{ $page['lede'] }}</p>
        <p class="site-page-hero__fine">{{ __('Effective since :date · Version :version', ['date' => $page['effective'], 'version' => $page['version']]) }}</p>
    </section>

    <article class="site-article">
        @foreach ($page['sections'] as $section)
            <section id="{{ $section['id'] }}">
                @if ($section['heading'] !== '')
                    <h2 class="site-article__h2">{{ $section['heading'] }}</h2>
                @endif
                @foreach ($section['blocks'] as $block)
                    @switch($block['kind'])
                        @case('note')
                            <p class="site-article__note">{{ $block['text'] }}</p>
                            @break
                        @case('ul')
                            <ul class="site-article__ul">
                                @foreach ($block['items'] as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                            @break
                        @case('dl')
                            <dl class="site-article__dl">
                                @foreach ($block['items'] as [$term, $text])
                                    <div>
                                        <dt>{{ $term }}</dt>
                                        <dd>{{ $text }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            @break
                        @default
                            <p>{{ $block['text'] }}</p>
                    @endswitch
                @endforeach
            </section>
        @endforeach

        <p class="site-article__cross">
            @if ($route === 'site.privacy')
                {{ __('This policy complements our') }} <a href="{{ route('site.terms') }}">{{ __('Terms of sale') }}</a>.
            @else
                {{ __('These terms are read with our') }} <a href="{{ route('site.privacy') }}">{{ __('Privacy policy') }}</a>.
            @endif
        </p>
    </article>

@endsection
