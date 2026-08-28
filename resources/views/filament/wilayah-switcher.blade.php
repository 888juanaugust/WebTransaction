@php
    /*
     * Which region the Owner is reading, changeable from anywhere.
     *
     * Rendered only for accounts with no pinned region — everyone else sees a
     * plain label of the region they belong to, because showing a disabled
     * select teaches people to wonder what would unlock it.
     */
    $context = app(\App\Domain\Regions\RegionContext::class);
    $daftar = \App\Models\Region::query()->where('aktif', true)->orderBy('kode')->get();
    $semua = $context->isOpenToAll();
    $aktif = $context->regionId();
    $bolehGanti = auth()->user()?->isOwner() && auth()->user()?->region_id === null;
@endphp

@if ($daftar->count() > 1 || $semua)
    <div class="fi-topbar-item flex items-center gap-2" style="margin-inline-end: .75rem">
        @if ($bolehGanti)
            <form method="post" action="{{ route('admin.wilayah-aktif') }}">
                @csrf
                <label class="sr-only" for="wilayah-aktif">Wilayah</label>
                <select
                    id="wilayah-aktif"
                    name="wilayah"
                    onchange="this.form.submit()"
                    class="fi-select-input rounded-lg border-gray-300 text-sm font-medium shadow-sm
                           dark:border-white/10 dark:bg-white/5"
                    style="padding-inline-end: 2rem"
                >
                    @foreach ($daftar as $wilayah)
                        <option value="{{ $wilayah->id }}" @selected($aktif === $wilayah->id)>
                            {{ $wilayah->label() }}
                        </option>
                    @endforeach
                    <option value="{{ \App\Http\Middleware\BindRegionContext::SEMUA }}" @selected($semua)>
                        Semua wilayah — hanya membaca
                    </option>
                </select>
            </form>
        @else
            <span class="fi-badge rounded-md px-2 py-1 text-xs font-medium"
                  style="background: rgb(7 49 133 / .08); color: #073185">
                {{ $context->region()?->label() ?? 'Wilayah' }}
            </span>
        @endif
    </div>
@endif
