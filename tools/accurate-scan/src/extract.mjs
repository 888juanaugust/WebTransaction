/**
 * Functions that run inside the ACCURATE page (via page.evaluate), so each is
 * self-contained: no imports, no closures over module scope.
 *
 * They read the DOM generically — labels, headers, buttons — because the scan
 * is written before anyone has seen ACCURATE's markup from here. A recon run
 * shows the real structure; selectors.json is where it gets tuned.
 *
 * What they return is raw and stays in memory: sanitize.mjs decides what of it
 * may be written down.
 */

/** The structure of whatever is in front of the user: a dialog if one is open, else the page. */
export function extractStructure() {
    const norm = (s) => String(s ?? '').replace(/\s+/g, ' ').trim();
    const visible = (el) => {
        if (!el || !(el instanceof Element)) return false;
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none';
    };
    const textOf = (el) => norm(el?.getAttribute?.('aria-label') || el?.innerText || el?.textContent || el?.value || el?.title);

    const dialogs = [...document.querySelectorAll('[role=dialog], [aria-modal=true], .modal.show, .modal.in, .x-window, .ui-dialog')].filter(visible);
    const root = dialogs.length > 0 ? dialogs[dialogs.length - 1] : document.body;
    const all = (sel) => [...root.querySelectorAll(sel)].filter(visible);

    const labelFor = (el) => {
        if (el.labels && el.labels.length > 0) return norm(el.labels[0].innerText);
        const aria = el.getAttribute('aria-label');
        if (aria) return norm(aria);
        const by = el.getAttribute('aria-labelledby');
        if (by) {
            const ref = document.getElementById(by.split(' ')[0]);
            if (ref) return norm(ref.innerText);
        }
        const wrapping = el.closest('label');
        if (wrapping) return norm(wrapping.innerText);
        // A label element or text cell just before the control, within its form row.
        const row = el.closest('.form-group, .form-row, .field, .x-form-item, tr, li, .row, div');
        if (row) {
            const lab = row.querySelector('label, .label, .control-label, .x-form-item-label, th, td');
            if (lab && lab !== el && !lab.contains(el)) return norm(lab.innerText);
        }
        let prev = el.previousElementSibling;
        while (prev && !norm(prev.innerText)) prev = prev.previousElementSibling;
        return prev ? norm(prev.innerText) : '';
    };

    const sectionFor = (el) => {
        const fs = el.closest('fieldset');
        if (fs) {
            const legend = fs.querySelector('legend');
            if (legend) return norm(legend.innerText);
        }
        const panel = el.closest('.panel, .card, .x-panel, section');
        const head = panel?.querySelector('.panel-heading, .card-header, .x-panel-header, h3, h4');
        return head ? norm(head.innerText) : '';
    };

    const controls = all('input, select, textarea, [contenteditable=true], [role=combobox], [role=checkbox], [role=switch], [role=radio]')
        .filter((el) => !(el.tagName === 'INPUT' && ['hidden', 'submit', 'button', 'image', 'reset'].includes(el.type)))
        // Controls inside a data grid are cells, not form fields.
        .filter((el) => !el.closest('[role=row], tbody tr'));

    const fields = controls.map((el) => {
        const tag = el.tagName.toLowerCase();
        const role = el.getAttribute('role');
        let type = tag === 'input' ? el.type || 'text' : tag;
        if (role === 'combobox' || el.getAttribute('aria-autocomplete')) type = 'combobox';
        if (role === 'checkbox' || role === 'switch') type = 'checkbox';
        const label = labelFor(el);
        const field = {
            label,
            placeholder: norm(el.getAttribute('placeholder')),
            type,
            required: el.required === true || el.getAttribute('aria-required') === 'true' || /\*\s*$/.test(label),
            section: sectionFor(el),
        };
        if (tag === 'select') field.options = [...el.options].map((o) => norm(o.text));
        if (type === 'checkbox' || type === 'radio') {
            field.checked = el.checked ?? el.getAttribute('aria-checked') === 'true';
        }
        if (type === 'number' || type === 'text') field.value = el.value ?? '';
        return field;
    });

    const headerCells = (grid) =>
        [...grid.querySelectorAll('th, [role=columnheader], .x-column-header-text')].filter(visible).map((h) => norm(h.innerText));

    const grids = all('table, [role=grid], [role=treegrid], .x-grid, .ag-root').map(headerCells).filter((g) => g.length > 0);

    return {
        title: document.title,
        headings: all('h1, h2, h3, h4, [role=heading], .page-title, .x-title-text').map(textOf),
        tabs: all('[role=tab], .nav-tabs > li > a, .x-tab-inner').map(textOf),
        buttons: all('button, [role=button], a.btn, input[type=button], input[type=submit]').map(textOf),
        columns: grids[0] ?? [],
        filters: all('[class*=filter] label, [class*=filter] [placeholder]').map((el) => norm(el.innerText || el.getAttribute('placeholder'))),
        fields,
        grids,
        inDialog: dialogs.length > 0,
    };
}

/**
 * Every visible thing a person could click, with enough about it to write a
 * selector from: text, tag, role, classes. Recon writes this (sanitised) so the
 * menu selectors can be set from the real markup.
 */
export function listClickables() {
    const norm = (s) => String(s ?? '').replace(/\s+/g, ' ').trim();
    const visible = (el) => {
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none';
    };
    const sel = 'a, button, [role=button], [role=menuitem], [role=tab], [role=treeitem], li, [onclick], [data-toggle], [tabindex]';
    return [...document.querySelectorAll(sel)]
        .filter(visible)
        .map((el) => {
            const r = el.getBoundingClientRect();
            return {
                text: norm(el.getAttribute('aria-label') || el.innerText || el.title).slice(0, 80),
                tag: el.tagName.toLowerCase(),
                role: el.getAttribute('role') || '',
                id: el.id || '',
                cls: (typeof el.className === 'string' ? el.className : '').split(/\s+/).slice(0, 4).join(' '),
                x: Math.round(r.x),
                y: Math.round(r.y),
            };
        })
        .filter((c) => c.text !== '');
}
