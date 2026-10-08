{{-- A panel page opened on its own (a bookmark, a link from an email) opens as a tab of the workspace instead. --}}
<script>
    if (window.self === window.top) {
        window.location.replace(@js(\App\Filament\Pages\Workspace::getUrl()) + '#open=' + encodeURIComponent(window.location.pathname + window.location.search));
    }
</script>
