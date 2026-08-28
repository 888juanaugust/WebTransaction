{{--
    Fills the latitude/longitude fields from the browser's geolocation the
    moment the form opens. Degrades to empty fields with a note when the
    sales declines the permission — a visit without coordinates is still a
    visit, just a weaker piece of evidence.
--}}
<div
    x-data="{ status: 'meminta lokasi…' }"
    x-init="
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    $wire.set('data.latitude', pos.coords.latitude.toFixed(7));
                    $wire.set('data.longitude', pos.coords.longitude.toFixed(7));
                    status = 'lokasi terekam ✓';
                },
                () => { status = 'lokasi ditolak — isi bisa dikosongkan'; },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        } else {
            status = 'browser tanpa geolokasi';
        }
    "
    class="text-sm"
    style="color:#6b7280"
>
    <span x-text="status"></span>
</div>
