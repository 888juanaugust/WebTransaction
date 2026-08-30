{{--
    The panel brand: the mark, and beside it who you are here.

    The role label answers the question shared computers make real — "which
    account is this open on?" — at a glance, in the corner every eye already
    visits. A Gudang account also shows its warehouse, because two packers'
    screens look identical except for this line. Nothing renders for a guest
    (the login page), where there is no one to describe yet.
--}}
@php
    use App\Support\Branding;

    $user = auth('web')->user();
    $logo = Branding::logoUrl();
    $dark = Branding::darkLogoUrl();
@endphp

<span class="flex items-center gap-x-3">
    @if ($logo)
        <img src="{{ $logo }}" alt="{{ config('perusahaan.nama_singkat') }}"
             @class(['h-7 w-auto', 'dark:hidden' => $dark !== null]) />
        @if ($dark)
            <img src="{{ $dark }}" alt="{{ config('perusahaan.nama_singkat') }}"
                 class="hidden h-7 w-auto dark:block" />
        @endif
    @else
        <span class="text-base font-bold">{{ config('perusahaan.nama_singkat') }}</span>
    @endif

    @if ($user)
        <span class="border-s border-gray-200 ps-3 leading-tight dark:border-white/10">
            <span class="block text-sm font-semibold text-gray-900 dark:text-white">
                {{ $user->role()->label() }}
            </span>
            @if ($user->role()->isWarehouseBound() && $user->warehouse)
                <span class="block text-xs text-gray-500 dark:text-gray-400">
                    {{ $user->warehouse->nama }}
                </span>
            @endif
        </span>
    @endif
</span>
