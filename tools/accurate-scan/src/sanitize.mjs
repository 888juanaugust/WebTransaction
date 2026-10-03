/**
 * What may be written to disk from a scanned page: structure, never data.
 *
 * The extractor returns what the screen shows; this decides which of it is a
 * label of the product (keep) and which could be the business's records —
 * customer names, amounts, dates, phone numbers (drop). The raw extraction
 * lives only in memory; nothing reaches docs/accurate/ without passing here.
 */

const MAX_LABEL = 80;

/** Labels whose dropdown options are records, not product enumerations. */
export const ENTITY_LABEL =
  /(pelanggan|pemasok|customer|vendor|supplier|karyawan|employee|penjual|sales(man)?|pengguna|user|akun|account|perkiraan|barang|item|produk|product|gudang|warehouse|cabang|branch|bank|proyek|project|departemen|department|nama|name|alamat|address|kontak|contact|mata uang|currency|kategori|category|grup|group|merk|brand)/i;

/**
 * One piece of on-screen text → kept (normalised) or null.
 * Product labels are short words; records have digits, @, money and dates.
 */
export function cleanText(input) {
    if (input === null || input === undefined) return null;
    const s = String(input).replace(/\s+/g, ' ').trim();
    if (s === '' || s.length > MAX_LABEL) return null;
    if (/@/.test(s)) return null; // email
    if ((s.match(/\d/g) ?? []).length >= 5) return null; // numbers, phone, NPWP, document no.
    if (/\brp\.?\s*\d/i.test(s)) return null; // money
    if (/\d{1,3}([.,]\d{3})+/.test(s)) return null; // 1.250.000
    if (/\d{1,2}[/\-. ]\d{1,2}[/\-. ]\d{2,4}/.test(s)) return null; // dates
    if (/\b(pt|cv|ud|tb|toko|bengkel)\.?\s+\S+/i.test(s)) return null; // a business name
    return s;
}

function cleanList(list, limit = 200) {
    const out = [];
    for (const item of list ?? []) {
        const t = cleanText(item);
        if (t !== null && !out.includes(t)) out.push(t);
        if (out.length >= limit) break;
    }
    return out;
}

/**
 * @param {object} raw from extract.mjs
 * @param {{preferences?: boolean}} options preferences pages keep switch
 *   states and short numeric settings — they are how the business configured
 *   ACCURATE, which is exactly what parity needs to know.
 */
export function sanitizePage(raw, { preferences = false } = {}) {
    const fields = [];
    for (const f of raw.fields ?? []) {
        const label = cleanText(f.label) ?? cleanText(f.placeholder);
        if (label === null) continue;

        const field = { label, type: String(f.type ?? 'text') };
        if (f.required) field.required = true;
        if (f.section) {
            const section = cleanText(f.section);
            if (section) field.section = section;
        }

        if (Array.isArray(f.options)) {
            if (ENTITY_LABEL.test(label)) {
                field.optionCount = f.options.length;
            } else {
                field.options = cleanList(f.options, 40);
            }
        }

        if (preferences) {
            if (typeof f.checked === 'boolean') field.checked = f.checked;
            if (typeof f.value === 'string' && /^\d{1,4}$/.test(f.value.trim())) field.value = f.value.trim();
        }

        fields.push(field);
    }

    return {
        title: cleanText(raw.title),
        headings: cleanList(raw.headings, 30),
        tabs: cleanList(raw.tabs, 30),
        columns: cleanList(raw.columns, 80),
        filters: cleanList(raw.filters, 40),
        buttons: cleanList(raw.buttons, 60),
        fields,
        grids: (raw.grids ?? []).map((g) => cleanList(g, 60)).filter((g) => g.length > 0),
    };
}
