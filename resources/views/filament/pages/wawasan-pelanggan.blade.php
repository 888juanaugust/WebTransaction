@php use App\Domain\Money; @endphp

<x-filament-panels::page>
    <div class="max-w-sm">
        <label class="text-sm font-medium" for="wawasan-pelanggan">Pelanggan</label>
        <select
            id="wawasan-pelanggan"
            wire:model.live="companyId"
            class="fi-select-input mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm
                   dark:border-white/10 dark:bg-white/5"
        >
            <option value="">— pilih pelanggan —</option>
            @foreach ($this->companyOptions() as $id => $nama)
                <option value="{{ $id }}">{{ $nama }}</option>
            @endforeach
        </select>
    </div>

    @if ($company === null)
        <p class="text-sm" style="color:#6b7280">
            Pilih pelanggan untuk melihat riwayat transaksi dan barang yang layak ditawarkan
            pada kunjungan berikutnya.
        </p>
    @else
        {{-- The agenda first: what to bring up on the visit. --}}
        <div class="grid gap-6 md:grid-cols-2">
            <div class="rounded-xl border p-4" style="border-color:#e5e7eb">
                <h3 class="text-sm font-semibold" style="color:#073185">
                    Belum pernah dibeli — laku di tempat lain
                </h3>
                <p class="mt-1 text-xs" style="color:#6b7280">
                    Barang aktif yang belum pernah dipesan {{ $company->nama }},
                    diurutkan dari yang paling laku di pelanggan lain.
                </p>
                <ul class="mt-2 space-y-1 text-sm">
                    @forelse ($belumPernah as $p)
                        <li>
                            <span class="font-medium">{{ $p->kode }}</span>
                            — {{ $p->merk }} · {{ $p->description }}
                        </li>
                    @empty
                        <li style="color:#6b7280">Pelanggan ini sudah pernah membeli semua barang aktif.</li>
                    @endforelse
                </ul>
            </div>

            <div class="rounded-xl border p-4" style="border-color:#e5e7eb">
                <h3 class="text-sm font-semibold" style="color:#b45309">
                    Berhenti dibeli — lebih dari 90 hari
                </h3>
                <p class="mt-1 text-xs" style="color:#6b7280">
                    Dulu rutin, sekarang tidak — tanyakan kenapa: pindah pemasok,
                    atau sekadar lupa.
                </p>
                <ul class="mt-2 space-y-1 text-sm">
                    @forelse ($berhenti as $b)
                        <li>
                            <span class="font-medium">{{ $b->sku }}</span>
                            — terakhir {{ \Illuminate\Support\Carbon::parse($b->terakhir)->format('d/m/Y') }},
                            total {{ number_format((int) $b->total_qty, 0, ',', '.') }} unit
                        </li>
                    @empty
                        <li style="color:#6b7280">Tidak ada yang berhenti — semua barang langganan masih jalan.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="rounded-xl border p-4" style="border-color:#e5e7eb">
            <h3 class="text-sm font-semibold">Riwayat transaksi</h3>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left" style="color:#6b7280">
                            <th class="py-1 pe-4 font-medium">Nomor</th>
                            <th class="py-1 pe-4 font-medium">Tanggal</th>
                            <th class="py-1 pe-4 font-medium">Status</th>
                            <th class="py-1 pe-4 font-medium">Baris</th>
                            <th class="py-1 font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($riwayat as $order)
                            <tr style="border-top:1px solid #f3f4f6">
                                <td class="py-1 pe-4 font-medium">{{ $order->nomor }}</td>
                                <td class="py-1 pe-4">{{ $order->created_at->format('d/m/Y') }}</td>
                                <td class="py-1 pe-4">{{ $order->status->label() }}</td>
                                <td class="py-1 pe-4">{{ $order->lines->count() }}</td>
                                <td class="py-1">{{ Money::format((int) $order->total_rupiah) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-2" style="color:#6b7280">Belum ada transaksi.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
