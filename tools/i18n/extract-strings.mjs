#!/usr/bin/env node
/**
 * Writes lang/<locale>.json with every string the code passes to __() as a key,
 * keeping the translations already in the file and adding the new keys empty.
 * The .php files under lang/<locale>/ (menu, fields, status) are separate and
 * are copied from lang/en/ by hand.
 *
 *   node tools/i18n/extract-strings.mjs id      → lang/id.json
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const locale = process.argv[2];
if (!locale || !/^[a-z]{2}(?:[-_][A-Za-z]{2,4})?$/.test(locale)) {
    console.error('Usage: node tools/i18n/extract-strings.mjs <locale>   e.g. id, en, zh_CN');
    process.exit(2);
}

const keys = new Set();
const SQ = /__\(\s*'((?:[^'\\]|\\.)*)'/g;
const DQ = /__\(\s*"((?:[^"\\]|\\.)*)"/g;
// A resource's model label is translated where it is read (ErpResource::getModelLabel).
const MODEL_LABEL = /\$(?:plural)?[mM]odelLabel\s*=\s*'((?:[^'\\]|\\.)*)'/g;
for (const dir of ['app', 'resources/views']) walk(path.join(root, dir));
function walk(p) {
    for (const e of fs.readdirSync(p, { withFileTypes: true })) {
        const full = path.join(p, e.name);
        if (e.isDirectory()) walk(full);
        else if (e.name.endsWith('.php')) scan(fs.readFileSync(full, 'utf8'));
    }
}
function scan(src) {
    for (const m of src.matchAll(SQ)) keys.add(m[1].replace(/\\(['\\])/g, '$1'));
    for (const m of src.matchAll(DQ)) keys.add(m[1].replace(/\\(["\\])/g, '$1'));
    for (const m of src.matchAll(MODEL_LABEL)) keys.add(m[1].replace(/\\(['\\])/g, '$1'));
}
// Keys of the form "menu.x", "fields.x", "status.x" live in the .php files, not here.
for (const k of [...keys]) if (/^(menu|fields|status)\.[\w.-]+$/.test(k)) keys.delete(k);

const file = path.join(root, 'lang', `${locale}.json`);
const existing = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {};
const out = {};
for (const k of [...keys].sort((a, b) => a.localeCompare(b))) out[k] = existing[k] ?? '';
const kept = Object.keys(existing).filter((k) => !(k in out));
fs.mkdirSync(path.dirname(file), { recursive: true });
fs.writeFileSync(file, JSON.stringify(out, null, 4) + '\n');
console.log(`${path.relative(root, file)}: ${Object.keys(out).length} keys, ${Object.values(out).filter((v) => v === '').length} untranslated${kept.length ? `, ${kept.length} stale key(s) dropped` : ''}.`);
