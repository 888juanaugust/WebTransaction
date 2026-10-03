# accurate-scan

A read-only scan of the business's ACCURATE Online database. It writes down what
ACCURATE *is* — every menu, list, form field, line-grid column, tab, report
parameter and preference switch — so that `docs/accurate/PARITY.md` can say,
row by row, what WebTransaction must do to match it.

**Not part of the application.** Laravel never loads it and CI does not run it.

## What it will and will not do

It runs against the live database, so it is built to be unable to change it:

| Layer | Rule |
|---|---|
| Network (`src/guard.mjs`) | Every request from every tab is classified before it leaves. Only plain reads go: GETs, and POSTs whose action says *list / detail / load / search…*. Anything whose action says *save, delete, approve, process, close, void, import, print, export…* is aborted, whatever its verb. WebSockets are not connected. Service workers are blocked. Third parties may serve files but receive nothing. |
| Clicks | Only menu entries, tabs, "+ / Tambah / Baru" and Batal/Tutup. Never Simpan, Hapus, Proses, Setujui, Tutup Buku, Impor, Cetak. |
| Disk (`src/sanitize.mjs`) | Labels and structure only. Anything that looks like a record — a name with PT/CV/Toko, an amount, a date, an email, a phone or document number — is dropped. A dropdown of customers or items is written as its *count*. Switch states and short numbers are kept on Preferensi screens only, because how the business configured ACCURATE is exactly what parity needs. |
| Challenges | A captcha or verification code stops the run. In `--headful` mode on your own machine it waits for **you** to answer it; it never answers one itself. |

Every refused request is logged (method, host, path — no ids, no query string)
and listed at the bottom of `docs/accurate/menu.md` as evidence.

`test/smoke.test.mjs` proves the rules in a real browser against a fake
ACCURATE (`test/fixtures/`) that autosaves a draft when a form opens, holds a
socket, and shows customer names and amounts: nothing reaches the fake server
but reads, and none of the records reach the output.

## Credentials

From the environment only — never on the command line, never in chat:

| Variable | |
|---|---|
| `ACCURATE_EMAIL` | The login |
| `ACCURATE_PASSWORD` | Its password |
| `ACCURATE_DATABASE` | Part of the database name, when the account has more than one |

In a Claude Code cloud session these are set in the environment's settings
(environment menu in the session title bar → Edit), and the environment's
network access must allow `accurate.id` and `*.accurate.id`. A new session
picks both up.

## Running it

```bash
cd tools/accurate-scan
npm install            # playwright only; the browser comes from /opt/pw-browsers here
npm test               # guard + sanitiser unit tests, and the offline smoke test

npm run recon          # log in, open the database, record the markup and requests
```

Read `.state/recon.json` and `.state/requests.json` (never committed). They show
which selectors find the menu, and whether any read ACCURATE needs was refused.
Tune `selectors.json` accordingly. `allowPost` takes **exact paths** for a read
the guard could not recognise — never put a save endpoint there.

```bash
npm run scan           # every module → docs/accurate/scan.json (resumable)
npm run render         # → docs/accurate/{menu,laporan,preferensi}.md, modul/*.md
```

Options: `--only=Penjualan` (one module), `--max-items=50`, `--delay=800`
(ms between actions), `--headful`, `--screenshots` (to `.state/`, never
committed).

**Before committing**, read the diff of `docs/accurate/`. The sanitiser is
conservative, but you are the last check that no customer, supplier or amount
went in.

### On your own computer instead

When the cloud session cannot reach ACCURATE, or ACCURATE asks for a
verification code:

```bash
cd tools/accurate-scan
npm install && npx playwright install chromium
export ACCURATE_EMAIL=... ACCURATE_PASSWORD=...   # in the shell, not in a file you commit
node scan.mjs --mode=full --headful
node render.mjs
```

Then commit `docs/accurate/`.
