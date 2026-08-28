@php
    use App\Domain\Money;
    $hariBeku = (int) config('penjualan.debt_freeze_days');
@endphp

<x-filament-widgets::widget>
    @if ($faktur->isNotEmpty())
        <div class="rounded-xl border p-4"
             style="{{ $beku
                ? 'border-color:#dc2626;background:rgb(220 38 38 / .06)'
                : 'border-color:#d97706;background:rgb(217 119 6 / .06)' }}">
            <h3 class="text-sm font-semibold" style="color:{{ $beku ? '#b91c1c' : '#b45309' }}">
                @if ($beku)
                    Akun Anda terkunci dari transaksi baru
                @else
                    Ada tagihan yang menua — mohon segera diselesaikan
                @endif
            </h3>

            <p class="mt-1 text-sm" style="color:#374151">
                @if ($beku)
                    Faktur berikut belum dibayar lebih dari {{ $hariBeku }} hari.
                    Pemesanan dibuka lagi begitu faktur ini lunas — hubungi tim kami bila
                    Anda sudah membayar.
                @else
                    Bila belum dibayar sampai {{ $hariBeku }} hari sejak tanggal faktur,
                    akun Anda otomatis terkunci dari pemesanan baru.
                @endif
            </p>

            <ul class="mt-2 space-y-1 text-sm" style="color:#111827">
                @foreach ($faktur as $f)
                    <li>
                        <span class="font-medium">{{ $f->nomor }}</span>
                        — {{ Money::format($f->amountOutstanding()) }},
                        terbit {{ $f->issued_on->format('d/m/Y') }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</x-filament-widgets::widget>
