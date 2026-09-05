{{--
    The line under the account's name in the sidebar footer.

    This is where the brand view's "which account is this open on?" moved
    to when the logo went back to being just the logo. Filament's sidebar
    user menu shows the avatar and the name; it has no second line, so this
    renders through the USER_MENU_BEFORE hook and the stylesheet places it
    below the trigger, aligned under the name. Staff see their role — and a
    packer their warehouse, because two packers' screens are identical but
    for this — and a buyer sees their company.

    Folded away with the labels when the sidebar is collapsed to icons.
--}}
@if (filled($keterangan))
    <div class="wt-akun-keterangan" x-show="$store.sidebar.isOpen">
        {{ $keterangan }}
    </div>
@endif
