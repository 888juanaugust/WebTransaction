#!/usr/bin/env node
/**
 * docs/accurate/scan.json → readable pages under docs/accurate/:
 *   modul/<modul>.md   every screen in a module: list columns, the "new" form
 *                      (fields, line grid, tabs)
 *   laporan.md         every report and its parameters
 *   preferensi.md      every preference tab, with the switches as set
 *   menu.md            the menu tree, one line per entry
 *
 * Rendering only — the scan already sanitised everything it wrote.
 */
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const docs = path.resolve(here, '../../docs/accurate');
const scanFile = process.argv[2] ? path.resolve(process.argv[2]) : path.join(docs, 'scan.json');
const outDir = process.argv[3] ? path.resolve(process.argv[3]) : docs;

const scan = JSON.parse(await fs.readFile(scanFile, 'utf8'));
await fs.mkdir(path.join(outDir, 'modul'), { recursive: true });

const slug = (s) =>
    s.toLowerCase().normalize('NFKD').replace(/[^\w\s-]/g, '').trim().replace(/[\s_]+/g, '-') || 'tanpa-nama';

const menu = ['# Menu ACCURATE', '', `Dipindai ${scan.scannedAt ?? '—'}.`, ''];
const reports = ['# Laporan ACCURATE', ''];
const prefs = ['# Preferensi ACCURATE', '', 'Saklar dan angka seperti tersetel di database yang dipindai.', ''];

for (const [moduleLabel, mod] of Object.entries(scan.modules ?? {})) {
    menu.push(`- **${moduleLabel}**`);
    const page = [`# ${moduleLabel}`, ''];

    for (const [item, entry] of Object.entries(mod.items ?? {})) {
        menu.push(`  - ${item}${entry.error ? ' _(gagal dibaca)_' : ''}`);
        if (entry.error) continue;

        if (entry.kind === 'report') {
            reports.push(`## ${item}`, '', ...fieldsTable(entry.view.fields), '');
            continue;
        }
        if (entry.kind === 'preferences') {
            prefs.push(`## ${item}`, '');
            for (const [tab, view] of Object.entries(entry.view.tabsRead ?? {})) {
                prefs.push(`### ${tab}`, '', ...fieldsTable(view?.fields ?? [], true), '');
            }
            if (Object.keys(entry.view.tabsRead ?? {}).length === 0) prefs.push(...fieldsTable(entry.view.fields, true), '');
            continue;
        }

        page.push(`## ${item}`, '');
        if (entry.view.columns.length) page.push(`**Kolom daftar:** ${entry.view.columns.join(' · ')}`, '');
        if (entry.view.buttons.length) page.push(`**Tombol:** ${entry.view.buttons.join(' · ')}`, '');
        if (entry.form) {
            page.push('### Formulir baru', '');
            page.push(...fieldsTable(entry.form.fields), '');
            for (const grid of entry.form.grids ?? []) page.push(`**Kolom rincian:** ${grid.join(' · ')}`, '');
            for (const [tab, view] of Object.entries(entry.form.tabsRead ?? {})) {
                page.push(`#### Tab: ${tab}`, '');
                if (view) {
                    page.push(...fieldsTable(view.fields));
                    for (const grid of view.grids ?? []) page.push('', `**Kolom rincian:** ${grid.join(' · ')}`);
                }
                page.push('');
            }
            if (entry.form.buttons.length) page.push(`**Tombol formulir:** ${entry.form.buttons.join(' · ')}`, '');
        }
    }
    await fs.writeFile(path.join(outDir, 'modul', `${slug(moduleLabel)}.md`), page.join('\n') + '\n');
}

if (scan.blockedRequests?.length) {
    menu.push('', '## Permintaan yang ditolak pemindai', '', 'Bukti bahwa tidak ada yang tersimpan ke ACCURATE selama pemindaian:', '');
    for (const b of scan.blockedRequests) menu.push(`- \`${b}\``);
}

await fs.writeFile(path.join(outDir, 'menu.md'), menu.join('\n') + '\n');
await fs.writeFile(path.join(outDir, 'laporan.md'), reports.join('\n') + '\n');
await fs.writeFile(path.join(outDir, 'preferensi.md'), prefs.join('\n') + '\n');
console.log(`[accurate-scan] rendered ${Object.keys(scan.modules ?? {}).length} modules into ${outDir}`);

function fieldsTable(fields, withState = false) {
    if (!fields || fields.length === 0) return ['_(tidak ada isian)_'];
    const head = withState ? '| Isian | Jenis | Nilai |' : '| Isian | Jenis | Wajib | Pilihan |';
    const rule = withState ? '|---|---|---|' : '|---|---|---|---|';
    const rows = fields.map((f) => {
        const label = (f.section ? `${f.section} › ` : '') + f.label;
        if (withState) {
            const state = typeof f.checked === 'boolean' ? (f.checked ? '✔ aktif' : '✘ mati') : (f.value ?? '');
            return `| ${esc(label)} | ${f.type} | ${state} |`;
        }
        const options = f.options ? f.options.join(', ') : f.optionCount !== undefined ? `(${f.optionCount} data)` : '';
        return `| ${esc(label)} | ${f.type} | ${f.required ? 'ya' : ''} | ${esc(options)} |`;
    });
    return [head, rule, ...rows];
}

function esc(s) {
    return String(s).replace(/\|/g, '\\|');
}
