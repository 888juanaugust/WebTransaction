{{-- The audit trail. The table carries everything; this only frames it. --}}
<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Setiap tindakan yang menyangkut uang tercatat di sini dengan sendirinya, lengkap
        dengan siapa yang melakukannya dan nilai sebelum-sesudahnya. Tidak ada tombol untuk
        mengubah atau menghapus baris di halaman ini — jejak audit yang bisa dirapikan tidak
        membuktikan apa pun.
    </p>

    {{ $this->table }}
</x-filament-panels::page>
