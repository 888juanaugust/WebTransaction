# Backups

CLAUDE.md asks for four things: nightly `pg_dump`, encrypted, off-box, and a
restore tested before launch. This is how each is done, and what to do at the
moment you actually need it.

**If the server is gone and you are reading this in a hurry, skip to
[Restoring](#restoring).**

---

## What a backup contains

Two artefacts per run:

| File | What |
|---|---|
| `…-db.sql.enc` | The whole database as plain SQL, encrypted |
| `…-files.tar.enc` | Everything under `storage/app/private`, encrypted |

The second one matters more than it looks. It holds the **raw supplier price
lists**, kept forever so a published price can be traced to the file it came
from, and the **faktur pajak exports exactly as they were filed**. Neither can
be regenerated from the database: the first was never in it, and the second
would come back as whatever today's code produces rather than what was actually
uploaded to the tax office.

The dump is plain SQL rather than the custom format. Custom is smaller and
restores faster, but it can only be read by a compatible `pg_restore` — and the
machine that has to read this is, by definition, not the machine that wrote it.
Plain SQL will still go in through whatever `psql` is to hand in five years.

Backups contain only **committed** data. A dump is taken by a separate process,
so an order somebody was halfway through placing is not in it. That is correct
and worth knowing.

---

## Setting it up

### 1. Generate a key

```bash
php artisan backup:key
```

It prints a line for `.env` and nothing else — it deliberately does not write
the key anywhere, because where the key lives is the most consequential
decision here and should not be made by a command.

```
BACKUP_ENCRYPTION_KEY=base64:…
```

**Three things about this key:**

- **Lose it and every backup you hold is unreadable.** There is no recovery, no
  support line, no brute force. Put a copy somewhere a fire cannot reach the
  same day as the office — a password manager the owner controls, or a sealed
  envelope in a different building.
- **It must not live with the backups.** A key in the same bucket as the file
  it opens is a longer filename, not encryption. If someone gets the storage
  credentials, that is bad; if they get the key too, they have every customer's
  NPWP and every price you charge.
- **Changing it does not re-encrypt old backups.** Keep the old key for as long
  as you keep anything written with it.

Backups are **refused** when no key is set. Writing them in the clear and
printing a warning would produce exactly the file we were trying to avoid, and
the warning would scroll past.

### 2. Point it somewhere off the machine

```dotenv
BACKUP_DISK=backups         # a disk you define in config/filesystems.php
BACKUP_PATH=backups
```

The default is `local`, which writes to the same VPS as the database. **That is
not a backup in the sense that matters** — the machine is the thing that fails.
It protects you from a dropped table and from nothing else.

A run to a local disk is recorded with `offsite = false`, the command says so,
and the dashboard shows a warning until it is fixed. That is deliberate: silence
would read as success.

Any Flysystem disk works — object storage, or a path that is itself a mount of
something remote. If you use a remote mount, set:

```dotenv
BACKUP_LOCAL_IS_OFFSITE=true
```

Only set that if it is true. The health check believes it.

### 3. Make sure the scheduler is running

The nightly run at 02:15 is a Laravel scheduled command, so the cron entry that
drives the scheduler has to exist:

```cron
* * * * * cd /var/www/webtransaction && php artisan schedule:run >> /dev/null 2>&1
```

Without that line nothing runs and nothing complains — which is the failure this
whole document exists to prevent.

### 4. Take one, and then restore it

```bash
php artisan backup:run
```

Then **practise the restore before you need it**, into a scratch database that
touches nothing real:

```bash
createdb webtransaction_practice
php artisan backup:restore --into=webtransaction_practice
psql webtransaction_practice -c 'SELECT count(*) FROM orders;'
dropdb webtransaction_practice
```

A restore procedure that has never been run is not a procedure. Do this before
launch, and again after any change to the storage destination.

---

## Checking it is still working

The commonest way a backup system fails is silently: cron stops, a credential
expires, a disk fills, somebody rotates the key. Nothing bangs. Six months later
the machine dies and the newest good copy predates the customers.

Three things watch for that:

- **The dashboard.** A banner appears above the work queues when the backups are
  unhealthy, and only then — a green tick that is always there stops being read
  within a week. Owner only, because it is not a job anybody else can act on.
- **`backup_runs`.** Every attempt writes a row, including failures. A table of
  successes alone cannot tell "nothing has run since Tuesday" from "everything
  has failed since Tuesday", and those need different phone calls.
- **The exit code.** `backup:run` exits non-zero on failure, and the schedule
  deliberately does not use `runInBackground()`, which would throw that away.

The four unhealthy states:

| State | Means | Do |
|---|---|---|
| `never` | Nothing has ever been verified | Run one. Read this page. |
| `failing` | The last attempt failed | Read `error` on the newest row |
| `stale` | Last good one is older than `BACKUP_STALE_AFTER_HOURS` | Check the cron entry |
| `local` | Recent and verified, but still on this machine | Set `BACKUP_DISK` |

### Why there is no plain "succeeded"

A run is only ever recorded as `verified`, and that means the artefact was
**downloaded again and decrypted** before the command reported success. Writing
a file proves the disk accepted bytes, which is not the question anybody has.
Reading it back catches a key that changed, a truncated upload, a full disk, and
storage that accepted the write and lost it — at creation, rather than at three
in the morning when it matters.

---

## Restoring

### Practising

```bash
php artisan backup:restore --into=webtransaction_practice
```

Restores into another database. Touches nothing live. Do this regularly.

### For real

```bash
php artisan backup:restore
```

Uses the newest verified backup and **replaces the live database** — the dump is
taken with `--clean --if-exists`, so it drops what is there. You will be asked to
confirm.

To go back to a specific night, find it first:

```bash
php artisan tinker --execute="App\Models\BackupRun::verified()->latest('started_at')->take(10)->get(['id','started_at','database_path'])->each(fn(\$r) => print(\"\$r->id  \$r->started_at  \$r->database_path\n\"));"

php artisan backup:restore --run=42
```

Add `--files` to unpack the archived price lists and faktur exports over
`storage/app/private` as well.

### Restoring onto a new machine

The order matters:

1. Install PHP, PostgreSQL, Redis, Caddy — see `REQUIREMENTS.md`.
2. Deploy the code and `composer install --no-dev`.
3. Put `APP_KEY` **and** `BACKUP_ENCRYPTION_KEY` in `.env`. Without the second
   one the backups are inert.
4. Point `BACKUP_DISK` at the storage holding them.
5. `createdb webtransaction`
6. `php artisan backup:restore --force`
7. Check the figures before letting anybody in:
   ```bash
   php artisan tinker --execute="echo App\Models\Order::count(), ' orders, ', App\Models\Invoice::count(), ' invoices';"
   php artisan test --filter=LedgerReconciliation
   ```

Step 7 is not optional. `psql` runs with `ON_ERROR_STOP=1` so a failed statement
aborts the restore rather than leaving a database that is missing whatever
failed — but a restore that ran cleanly and restored last week's data is still
the wrong outcome, and only the numbers will tell you.

---

## What is deliberately not here

- **No incremental or point-in-time recovery.** A nightly full dump means up to
  a day of orders can be lost. WAL archiving would close that, and it needs an
  archive destination and a retention policy of its own. For a business doing
  tens of orders a day, re-entering a morning's paperwork is a bad day; running
  a PITR setup nobody understands is a worse one. Revisit when the daily volume
  makes a day's loss unacceptable.
- **No backup of Redis.** It holds the queue and the cache. A lost queue means
  some webhook jobs are re-driven, which they are built to survive — every job
  is idempotent.
- **No automatic restore testing.** The test suite proves a restore works
  (`tests/Feature/BackupTest.php` dumps, encrypts, restores into a scratch
  database and reads the rows back). Doing that against production storage on a
  schedule would need somewhere safe to restore into, which is a decision about
  cost rather than about code.
