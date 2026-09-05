{{--
    A section heading in the sidebar — "Utama" above the menu.

    Real text, not a CSS pseudo-element, so it is read out and selectable.
    Hidden with the labels when the sidebar is folded to icons, since a
    heading with nothing legible under it is a stray word.
--}}
<div class="wt-sidebar-bagian" x-show="$store.sidebar.isOpen" aria-hidden="true">
    {{ $label }}
</div>
