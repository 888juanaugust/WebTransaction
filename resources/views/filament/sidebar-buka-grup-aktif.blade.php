{{--
    Un-fold the group that holds the current page.

    Filament seeds each group's collapsed state into localStorage once, on
    the first visit, and trusts it from then on; nothing re-opens a group
    because the page inside it became current. So with every group folded
    by default, a link somebody follows into Jurnal lands them on a page
    whose own menu entry is hidden under "Buku besar ›".

    This runs in the SIDEBAR_NAV_END hook — after Filament's own inline
    script has marked the folded groups and before Alpine initialises — and
    removes the active group from the stored list, then undoes the early
    hide Filament applied. Alpine's $persist reads the corrected value, so
    the group renders open and stays open until the person folds it.

    Done here rather than in the stylesheet on purpose: forcing `display`
    with !important looked right and did nothing, because x-collapse also
    writes `height: 0; overflow: hidden` inline — measured, the item stayed
    invisible. Correcting the store is the only version that agrees with
    what Filament will do next.
--}}
<script>
    (() => {
        const active = Array.from(
            document.querySelectorAll('.fi-sidebar-group.fi-active[data-group-label]'),
        ).map((group) => group.dataset.groupLabel)

        if (active.length === 0) {
            return
        }

        let stored = []

        try {
            stored = JSON.parse(localStorage.getItem('collapsedGroups')) || []
        } catch (e) {
            stored = []
        }

        const next = stored.filter((label) => ! active.includes(label))

        if (next.length !== stored.length) {
            localStorage.setItem('collapsedGroups', JSON.stringify(next))
        }

        active.forEach((label) => {
            const group = document.querySelector(
                '.fi-sidebar-group[data-group-label="' + CSS.escape(label) + '"]',
            )

            if (! group) {
                return
            }

            group.classList.remove('fi-collapsed')

            const items = group.querySelector('.fi-sidebar-group-items')

            if (items) {
                items.style.display = ''
            }
        })
    })()
</script>
