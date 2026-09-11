@extends('layouts.publik')

@section('judul', __('publik.rencana.judul'))
@section('deskripsi', __('publik.rencana.deskripsi', ['nama' => config('perusahaan.nama')]))

@section('konten')

    @include('publik.partials.hero-halaman', [
        'judul' => __('publik.rencana.judul'),
        'lede' => __('publik.rencana.lede'),
    ])

    <section class="mx-auto max-w-4xl px-4 py-16 sm:py-20">
        <ol class="divide-y divide-line border-y border-line">
            @foreach (\App\Support\Perusahaan::records('rencana') as $item)
                @php
                    // Blue = done or happening now, grey = planned. Red is never
                    // used decoratively — it means something is wrong, and
                    // nothing on a roadmap is wrong.
                    $gaya = match ($item['status']) {
                        'selesai' => [__('publik.rencana.selesai'), 'bg-brand-50 text-brand-700'],
                        'berjalan' => [__('publik.rencana.berjalan'), 'bg-brand-50 text-brand-700'],
                        default => [__('publik.rencana.rencana'), 'bg-ground-2 text-ink-muted'],
                    };
                @endphp

                <li class="grid gap-2 py-6 sm:grid-cols-[120px_1fr] sm:gap-8">
                    <span class="inline-flex w-fit items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $gaya[1] }}">{{ $gaya[0] }}</span>
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-ink">{{ $item['judul'] }}</h2>
                        <p class="mt-1.5 leading-relaxed text-ink-muted">{{ $item['deskripsi'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>

        <p class="mt-10 text-sm text-ink-muted">
            {{ __('publik.rencana.catatan') }}
        </p>
    </section>

@endsection
