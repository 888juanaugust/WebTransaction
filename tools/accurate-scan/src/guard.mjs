/**
 * Decides, for every request the browser makes, whether it may leave.
 *
 * The scan runs against the business's live ACCURATE database, so the rule is
 * built the other way round from a normal crawler: a request is refused unless
 * it is plainly a read. Button-text rules in scan.mjs are a second layer; this
 * is the one that holds even if a click lands on "Simpan" by mistake, because
 * the save never reaches ACCURATE.
 *
 * Pure functions only: no Playwright import, so `node --test` covers them
 * without a browser.
 */

/** Anything that sounds like changing data. Wins over every allow rule. */
export const WRITE_WORDS =
  /(save|simpan|delete|hapus|remove|approve|setuju|reject|tolak|process|proses|close|tutup|void|batal|cancel|import|impor|bulk|upload|unggah|send|kirim|submit|create|update|edit|ubah|lock|kunci|transfer|post|batch|duplicate|copy|salin|restore|reopen|recalc|generate|sync|email|whatsapp|print|cetak|export|ekspor|attach|lampir|\b(?:run|execute|reset|assign|backup|mark|merge|split|move|set)\b)/i;

/** POSTs that only read: list/detail/lookup calls and the menu/preference loaders. */
export const READ_WORDS =
  /(list|detail|get|search|cari|lookup|load|find|menu|count|view|read|query|fetch|info|param|filter|preference|preferensi|setting|module|access|privilege|right|ping|keep-?alive|heartbeat|check|validate|autocomplete|suggest)/i;

/** The exchange that logs in and opens a database. Allowed even as POST. */
export const SESSION_WORDS = /(login|signin|sign-in|auth|oauth|token|session|open-?db|db-?list|switch-?db|database)/i;

/** Hosts the scan may talk to at all for anything but plain GETs. */
export const ACCURATE_HOST = /(^|\.)accurate\.id$/i;

/**
 * @param {{method: string, url: string, resourceType?: string}} req
 * @param {{allowPost?: string[]}} [options] exact ACCURATE paths that may be
 *   POSTed even though their name says nothing — the login form's action, as
 *   seen in a recon run. Paths, not patterns: an entry unlocks one endpoint.
 * @returns {{allow: boolean, reason: string}}
 */
export function classifyRequest({ method, url, resourceType = '' }, { allowPost = [] } = {}) {
    let parsed;
    try {
        parsed = new URL(url);
    } catch {
        return { allow: false, reason: 'unparseable-url' };
    }

    if (parsed.protocol === 'data:' || parsed.protocol === 'blob:') {
        return { allow: true, reason: 'inline' };
    }

    const verb = method.toUpperCase();
    const path = safeDecode(parsed.pathname);
    // The action is the last segment ("save.do", "list.do"); the segments
    // before it name the module, and module names say "transfer" or "close"
    // without meaning it — Pemindahan barang is item-transfer, and its list
    // must load.
    const action = path.split('/').filter(Boolean).pop() ?? '';
    const target = action + ' ' + safeDecode(parsed.search);
    const accurate = ACCURATE_HOST.test(parsed.hostname);

    // Static assets: scripts, styles, fonts, images. Reading them changes nothing.
    if (verb === 'GET' && ['script', 'stylesheet', 'font', 'image', 'media'].includes(resourceType)) {
        return { allow: true, reason: 'static' };
    }

    if (!accurate) {
        // Third-party GETs (a CDN) may load; third-party beacons may not.
        return verb === 'GET' || verb === 'HEAD'
            ? { allow: true, reason: 'third-party-read' }
            : { allow: false, reason: 'third-party-write' };
    }

    if (verb === 'POST' && allowPost.includes(parsed.pathname)) {
        return { allow: true, reason: 'allow-listed' };
    }

    // Checked before every allow rule below, so "token", "database" or
    // "detail" in a URL never unlock "save".
    if (WRITE_WORDS.test(target)) {
        return { allow: false, reason: 'write-word' };
    }

    if (verb === 'GET' || verb === 'HEAD' || verb === 'OPTIONS') {
        return { allow: true, reason: 'read-verb' };
    }

    if (verb === 'POST' && (READ_WORDS.test(action) || SESSION_WORDS.test(action))) {
        return { allow: true, reason: 'read-post' };
    }

    return { allow: false, reason: 'unclassified-write' };
}

function safeDecode(s) {
    try {
        return decodeURIComponent(s);
    } catch {
        return s;
    }
}

/**
 * A request as it may be logged: method, host and path with ids masked.
 * The query string is dropped: it can carry record ids and search terms.
 */
export function describeRequest({ method, url }) {
    try {
        const u = new URL(url);
        const path = u.pathname.replace(/\d+/g, ':n');
        return `${method.toUpperCase()} ${u.hostname}${path}`;
    } catch {
        return `${method.toUpperCase()} <unparseable>`;
    }
}

/** Buttons the crawler never clicks, whatever the network guard would do. */
export const FORBIDDEN_BUTTON =
  /(simpan|save|hapus|delete|proses|process|setuju|approve|tolak|reject|tutup buku|kunci|impor|import|kirim|send|unggah|upload|cetak|print|ekspor|export|lunas|posting|batalkan|void|duplikat|salin)/i;

/** Buttons that open an empty "new record" form. */
export const NEW_RECORD_BUTTON = /^\s*(\+|(tambah|baru|buat|new|add)\b)/i;

/** Buttons that close a form without saving. */
export const CLOSE_BUTTON = /^\s*(batal|tutup|close|kembali|back|×|x|tidak)\s*$/i;

/** Text of a clickable thing → may the crawler press it? */
export function mayClick(text) {
    const t = String(text ?? '').trim();
    if (t === '') return false;
    return !FORBIDDEN_BUTTON.test(t) || CLOSE_BUTTON.test(t);
}
