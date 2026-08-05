{{--
    The warehouse's screen. Just the table — everything a packer needs is a
    column or an action on it, and a summary panel here would only be numbers
    they cannot act on.
--}}
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
