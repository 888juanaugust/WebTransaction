#!/usr/bin/env node
/**
 * Wraps the UI's inline English in __() so a lang/<locale>.json can translate
 * it without a code change. Idempotent: a literal already inside __() is left
 * alone. No dependencies.
 *
 *   node tools/i18n/wrap-literals.mjs            rewrite app/ and resources/views/
 *   node tools/i18n/wrap-literals.mjs --check    list what it would change, exit 1 if anything
 *   node tools/i18n/wrap-literals.mjs path ...   limit to these files or folders
 *
 * What it wraps (the TranslationGuardTest fails on the same things):
 *  - the literal argument of ->label(), ->placeholder(), ->title() and the other
 *    label-like Filament methods listed in METHODS, single- or double-quoted;
 *    an interpolated "{$x} approved" becomes __(':x approved', ['x' => $x]);
 *  - Tab::make('Text'), Section::make('Text') and the other MAKES, when the text
 *    has an uppercase letter or a space (a key-like name is left alone);
 *  - the right-hand literals of => inside ->options([...]) arrays and match (...) {...}
 *    blocks under app/Filament, and inside label(), getLabel(), help(), options(),
 *    description(), title(), heading() methods anywhere under app/, when the
 *    text has an uppercase letter or a space;
 *  - the message of new RuntimeException('...'), shown to the user in notifications;
 *  - the label of the column helpers (static::money('total', 'Total') and the like), the
 *    texts of $fail('...') in validation rules, and the headers listed in exportHeaders();
 *  - in Blade views, text between tags (>Text<) and placeholder="Text" attributes.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const args = process.argv.slice(2);
const check = args.includes('--check');
const targets = args.filter((a) => a !== '--check');
const roots = targets.length ? targets.map((t) => path.resolve(root, t)) : [path.join(root, 'app'), path.join(root, 'resources/views')];

export const METHODS = ['label', 'placeholder', 'helperText', 'hint', 'title', 'heading', 'description', 'modalHeading', 'modalDescription', 'modalSubmitActionLabel', 'modalCancelActionLabel', 'emptyStateHeading', 'emptyStateDescription', 'body', 'tooltip', 'successNotificationTitle', 'navigationLabel', 'addActionLabel'];
export const MAKES = ['Tab', 'Section', 'Fieldset', 'Stat', 'TableColumn', 'Step'];
export const LABEL_METHODS = ['label', 'getLabel', 'help', 'options', 'description', 'title', 'heading', 'statuses'];
// Column helpers whose second argument is the column's label: static::money('total', 'Total').
export const LABEL_HELPERS = String.raw`(?:static|self|PricedDocumentForm|SettlementLineFields)::(?:money|text|date|quantity|amount)`;

const SQ = String.raw`'(?:[^'\\]|\\.)*'`; // a PHP single-quoted literal
const DQ = String.raw`"(?:[^"\\]|\\.)*"`; // a PHP double-quoted literal

const isText = (s) => /[A-Za-z]/.test(s) && !/^heroicon/.test(s) && !s.startsWith('App\\') && !/^[a-z-]+:\s*$/.test(s);
const isLabelish = (s) => isText(s) && /[A-Z ]/.test(s);

const files = [];
for (const r of roots) walk(r);
function walk(p) {
    const st = fs.statSync(p);
    if (st.isDirectory()) {
        for (const e of fs.readdirSync(p)) walk(path.join(p, e));
    } else if (p.endsWith('.php') && !p.endsWith('welcome.blade.php')) {
        files.push(p);
    }
}

let changedFiles = 0;
let changes = 0;
for (const file of files) {
    const before = fs.readFileSync(file, 'utf8');
    const after = file.endsWith('.blade.php') ? rewriteBlade(before) : rewritePhp(before, file);
    if (after === before) continue;
    changedFiles++;
    const n = count(after, '__(') - count(before, '__(');
    changes += n;
    if (check) {
        console.log(`${path.relative(root, file)}: ${n} literal(s) to wrap`);
    } else {
        fs.writeFileSync(file, after);
    }
}
console.log(`${check ? 'Would wrap' : 'Wrapped'} ${changes} literal(s) in ${changedFiles} file(s).`);
if (check && changes > 0) process.exit(1);

function count(s, needle) {
    return s.split(needle).length - 1;
}

// ---- PHP ------------------------------------------------------------------

function rewritePhp(src, file) {
    let out = src;

    // 1. ->label('Text') and friends, single-quoted.
    out = out.replace(new RegExp(String.raw`(->(?:${METHODS.join('|')})\(\s*)(${SQ})(\s*\))`, 'g'), (m, open, lit, close) =>
        isText(inner(lit)) ? `${open}__(${lit})${close}` : m);

    // 2. ->title("{$x} approved") and friends, double-quoted: placeholders for the interpolation.
    out = out.replace(new RegExp(String.raw`(->(?:${METHODS.join('|')})\(\s*)(${DQ})(\s*\))`, 'g'), (m, open, lit, close) => {
        const converted = convertDoubleQuoted(lit);
        return converted === null ? m : `${open}${converted}${close}`;
    });

    // 3. Tab::make('Text') and the other label-carrying makes.
    out = out.replace(new RegExp(String.raw`((?:${MAKES.join('|')})::make\(\s*)(${SQ})`, 'g'), (m, open, lit) =>
        isLabelish(inner(lit)) ? `${open}__(${lit})` : m);

    // 4. => 'Text' inside ->options([...]) and match (...) {...} blocks (Filament), and inside label-like methods (anywhere).
    const inFilament = file.includes(`${path.sep}app${path.sep}Filament${path.sep}`);
    if (inFilament) {
        out = rewriteBlocks(out, /->options\(\s*\[/g, '[', ']');
        out = rewriteBlocks(out, /\bmatch\s*\(/g, '(', ')', true);
    }
    out = rewriteBlocks(out, new RegExp(String.raw`\bfunction\s+(?:${LABEL_METHODS.join('|')})\s*\(`, 'g'), '(', ')', true);

    // 5. Messages the user reads: new RuntimeException('...') and $fail('...'), single- or double-quoted.
    for (const opener of [String.raw`new\s+\\?RuntimeException\(\s*`, String.raw`\$fail\(\s*`]) {
        out = out.replace(new RegExp(String.raw`(${opener})(${SQ})`, 'g'), (m, open, lit) => (isText(inner(lit)) ? `${open}__(${lit})` : m));
        out = out.replace(new RegExp(String.raw`(${opener})(${DQ})`, 'g'), (m, open, lit) => {
            const converted = convertDoubleQuoted(lit);
            return converted === null ? m : `${open}${converted}`;
        });
    }

    // 6. static::money('total', 'Total') and the other column helpers: the label.
    out = out.replace(new RegExp(String.raw`(${LABEL_HELPERS}\(\s*${SQ}\s*,\s*)(${SQ})`, 'g'), (m, open, lit) => (isText(inner(lit)) ? `${open}__(${lit})` : m));

    // 7. The spreadsheet headers listed in exportHeaders().
    out = rewriteBlocks(out, /\bfunction\s+exportHeaders\s*\(/g, '(', ')', true, wrapListValues);

    return out;
}

function wrapListValues(block) {
    return block.replace(new RegExp(String.raw`([\[,]\s*)(${SQ})(?=\s*[,\]])`, 'g'), (m, before, lit) => (isText(inner(lit)) ? `${before}__(${lit})` : m));
}

/** The content of a quoted literal, quotes stripped (escapes kept). */
function inner(lit) {
    return lit.slice(1, -1);
}

/**
 * Finds every opener, bracket-matches from `open` to its `close`, and wraps the
 * => 'Text' values inside. With `thenBraces`, the block is the {...} that follows
 * the matched parentheses (a match expression or a method body).
 */
function rewriteBlocks(src, opener, open, close, thenBraces = false, wrap = wrapArrowValues) {
    let out = '';
    let last = 0;
    let m;
    opener.lastIndex = 0;
    while ((m = opener.exec(src)) !== null) {
        let start = src.indexOf(open, m.index + m[0].length - 1);
        if (start < 0 || start < last) continue;
        let end = matchBracket(src, start, open, close);
        if (end < 0) continue;
        if (thenBraces) {
            const brace = src.indexOf('{', end);
            if (brace < 0 || src.slice(end + 1, brace).trim() !== '' && !/^\s*:\s*\??[\w\\|]+\s*$/.test(src.slice(end + 1, brace))) {
                // not followed by a body (an abstract method, an interface method, or something else)
                opener.lastIndex = end;
                continue;
            }
            start = brace;
            end = matchBracket(src, brace, '{', '}');
            if (end < 0) continue;
        }
        out += src.slice(last, start) + wrap(src.slice(start, end + 1));
        last = end + 1;
        opener.lastIndex = last;
    }
    return out + src.slice(last);
}

function matchBracket(src, start, open, close) {
    let depth = 0;
    let quote = null;
    for (let i = start; i < src.length; i++) {
        const c = src[i];
        if (quote) {
            if (c === '\\') i++;
            else if (c === quote) quote = null;
            continue;
        }
        if (c === "'" || c === '"') quote = c;
        else if (c === '/' && src[i + 1] === '/') i = src.indexOf('\n', i);
        else if (c === open) depth++;
        else if (c === close && --depth === 0) return i;
    }
    return -1;
}

function wrapArrowValues(block) {
    return block
        .replace(new RegExp(String.raw`(=>\s*)(${SQ})`, 'g'), (m, arrow, lit) => (isLabelish(inner(lit)) ? `${arrow}__(${lit})` : m))
        .replace(new RegExp(String.raw`(\breturn\s+)(${SQ})(\s*;)`, 'g'), (m, ret, lit, end) => (isLabelish(inner(lit)) ? `${ret}__(${lit})${end}` : m));
}

/** "Giro {$record->giro->number} cleared" → __('Giro :number cleared', ['number' => $record->giro->number]); null when not worth it. */
function convertDoubleQuoted(lit) {
    const body = inner(lit);
    if (body.includes('\\')) return null; // escapes we would rather not reason about
    if (!isText(body)) return null;
    const replacements = [];
    const names = new Map();
    let text = '';
    for (let i = 0; i < body.length; i++) {
        if (body[i] === '{' && body[i + 1] === '$') {
            const end = matchBracket(body, i, '{', '}');
            if (end < 0) return null;
            text += ':' + placeholder(body.slice(i + 2, end), names, replacements);
            i = end;
        } else if (body[i] === '$' && /[A-Za-z_]/.test(body[i + 1] ?? '')) {
            const m = /^\$[A-Za-z_]\w*(?:->\w+|\[\w+\]|\['[^']*'\])*/.exec(body.slice(i));
            text += ':' + placeholder(m[0].slice(1), names, replacements);
            i += m[0].length - 1;
        } else {
            text += body[i];
        }
    }
    const quoted = `'${text.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
    if (replacements.length === 0) return `__(${quoted})`;
    return `__(${quoted}, [${replacements.map(([n, e]) => `'${n}' => $${e}`).join(', ')}])`;
}

function placeholder(expr, names, replacements) {
    let base = expr.replace(/\[['"]?(\w+)['"]?\]$/, '->$1').split('->').pop().replace(/\W/g, '') || 'value';
    let name = base;
    for (let n = 2; names.has(name) && names.get(name) !== expr; n++) name = base + n;
    if (!names.has(name)) {
        names.set(name, expr);
        replacements.push([name, expr]);
    }
    return name;
}

// ---- Blade ------------------------------------------------------------------

function rewriteBlade(src) {
    let out = src;
    // >Text< between tags, on one line, starting with a capital letter, with no Blade or PHP inside.
    out = out.replace(/>([ \t]*\n?[ \t]*)([A-Z][^<>{}@\n]*?)([ \t]*\n?[ \t]*)</g, (m, pre, text, post) => {
        const t = text.trim();
        if (!isText(t) || /[{}]/.test(t)) return m;
        return `>${pre}{{ __('${t.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}') }}${post}<`;
    });
    // placeholder="Text"
    out = out.replace(/(\splaceholder=")([A-Z][^"{}@]*)(")/g, (m, open, text, close) => `${open}{{ __('${text.replace(/'/g, "\\'")}') }}${close}`);
    return out;
}
