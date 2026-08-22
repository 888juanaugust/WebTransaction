{{-- Shape as well as colour: a tick and a cross read the same to a colourblind
     reader if only the hue changes. --}}
@if ($lulus)
    <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full
                 bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400">
        <x-heroicon-m-check class="size-3.5" />
    </span>
@else
    <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full
                 bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400">
        <x-heroicon-m-x-mark class="size-3.5" />
    </span>
@endif
