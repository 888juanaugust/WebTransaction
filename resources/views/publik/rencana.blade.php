@extends('layouts.publik')

@section('judul', 'Rencana Pengembangan')
@section('deskripsi', 'Rencana pengembangan layanan ' . config('perusahaan.nama') . '.')

@section('konten')

    @include('publik.partials.hero-halaman', [
        'kicker' => 'Arah kami',
        'judul' => 'Rencana Pengembangan',
        'lede' => 'Apa yang sedang kami kerjakan dan apa yang akan datang berikutnya.',
    ])

    <section class="mx-auto max-w-4xl px-4 py-16">
        <ol class="relative space-y-8 border-l-2 border-slate-200 pl-8">
            @foreach (config('perusahaan.rencana') as $item)
                @php
                    // Blue = happening now, grey = planned. Red is never used
                    // decoratively — it means something is wrong, and nothing
                    // on a roadmap is wrong.
                    $gaya = match ($item['status']) {
                        'selesai' => ['Selesai', 'bg-brand-600', 'bg-brand-50 text-brand-700'],
                        'berjalan' => ['Sedang berjalan', 'bg-brand-600', 'bg-brand-50 text-brand-700'],
                        default => ['Rencana', 'bg-slate-300', 'bg-slate-100 text-slate-600'],
                    };
                @endphp

                <li class="relative">
                    <span class="absolute -left-[41px] top-6 flex h-4 w-4 rounded-full ring-4 ring-white {{ $gaya[1] }}"></span>

                    <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-900/5">
                        <div class="flex flex-wrap items-center gap-3">
                            <h2 class="text-lg font-semibold text-slate-900">{{ $item['judul'] }}</h2>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $gaya[2] }}">{{ $gaya[0] }}</span>
                        </div>

                        <p class="mt-2 leading-relaxed text-slate-600">{{ $item['deskripsi'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>

        <p class="mt-12 text-sm text-slate-500">
            Rencana dapat berubah menyesuaikan kebutuhan pelanggan dan kesiapan operasional.
        </p>
    </section>

@endsection
