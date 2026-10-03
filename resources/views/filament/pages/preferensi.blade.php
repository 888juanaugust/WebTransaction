<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">
        Saklar di bawah mengatur hal-hal yang dilakukan sistem ini tetapi tidak dilakukan ACCURATE.
        Semuanya menyala sejak awal — itulah perilaku hari ini. Mematikan satu saklar membuat sistem
        bekerja seperti ACCURATE pada bagian itu. Setiap perubahan tercatat di log audit.
    </p>

    <form wire:submit="simpan" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">
            Simpan preferensi
        </x-filament::button>
    </form>

    @if (count($this->saklarMenyusul()) > 0)
        <div class="text-sm text-gray-600 dark:text-gray-400">
            <p class="font-medium">Saklar yang menyusul</p>
            <ul class="mt-1 list-disc ps-5">
                @foreach ($this->saklarMenyusul() as $fitur)
                    <li>{{ $fitur->label() }} — berlaku mulai fase {{ $fitur->berlakuMulaiFase() }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</x-filament-panels::page>
