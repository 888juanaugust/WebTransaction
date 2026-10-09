{{-- The gallery: whatever the Owner wants seen, the goods, the warehouse, the team. Captions in the language being spoken. --}}
@php use App\Client\Site\Copy; @endphp
<section class="site-section" data-reveal>
    <h2 class="site-h2">{{ __('In pictures') }}</h2>
    <ul class="site-gallery">
        @foreach ($photos as $photo)
            <li class="site-gallery__item">
                <figure>
                    <img class="site-gallery__image" src="{{ $photo->url() }}" alt="{{ Copy::pick($photo->title) }}" loading="lazy" width="800" height="600">
                    <figcaption class="site-gallery__caption">{{ Copy::pick($photo->title) }}</figcaption>
                </figure>
            </li>
        @endforeach
    </ul>
</section>
