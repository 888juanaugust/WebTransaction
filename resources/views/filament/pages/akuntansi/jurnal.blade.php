{{-- The journal register. The table carries everything; this only frames it. --}}
<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Setiap jurnal terbentuk dari sebuah dokumen dan tidak pernah diubah. Koreksi dilakukan
        dengan jurnal pembalik, bukan dengan mengedit baris yang sudah ada.
    </p>

    {{ $this->table }}
</x-filament-panels::page>
