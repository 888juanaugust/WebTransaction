# Operations and deployment — design

Sub-project 6 of `docs/ROADMAP.md`. Status: approved 2026-10-14.

## Goal

Central goes live on one VPS. The base documents the server (DEPLOY.md: Caddy, PHP-FPM,
PostgreSQL 16, Redis, supervisor, cron, `/srv/erp`, user `erp`) and leaves backups to a
hand-written cron with `age` and `rclone`; it has no health check, no heartbeat, no
integrity sweep, no readiness check, no deploy script and no deploy workflow. The previous
system (commit `0683d20`) had all of these: `backup:run/restore/key` (pg_dump → libsodium
secretstream XChaCha20-Poly1305 → a disk, read back and verified, 14 daily + 12 monthly),
seven health checks with an hourly critical mail to the Owner (6-hour throttle, re-armed on
recovery), a nightly ledger integrity sweep, launch readiness (nine automatic checks and six
attested items), and a deploy kit (`deploy/`, `deploy.yml` on the `production` branch).
Decisions with the user: backups to an **S3-compatible bucket**; alerts to **every active
administrator plus an optional address**; integrity findings on the **Operations screen and
in a daily mail while they persist**, nothing repaired, no bell, no close block; readiness =
**automatic checks plus attestations**.


## 1. Module and configuration
- `App\Client\Modules\OpsModule` (`central-ops`, always on): screens Operations
  (`client__operations`, Modul::Settings, Tool, sort 40) and Launch Readiness
  (`client__launch-readiness`, Modul::Settings, Setup, sort 41); morph `backup_run`,
  `launch_attestation`; commands `central:backup`, `central:backup-key`, `central:restore`,
  `central:health`, `central:integrity`, `central:launch-check`; schedule (every event
  `withoutOverlapping()->onOneServer()`, as `OpeningBalancesTest` demands): the heartbeat
  every minute (`Schedule::call`), `central:health --alert` hourly, `central:integrity
  --notify` daily 01:30, `central:backup` daily 02:15 (`withoutOverlapping(60)`).
- `app/Client/config/ops.php` (merged as `ops`): `backup` (disk `BACKUP_DISK` default
  `local`, path `BACKUP_PATH` `backups`, key `BACKUP_ENCRYPTION_KEY`, `local_is_offsite`,
  include files + root `storage/app/private`, keep daily 14, keep monthly 12, stale hours 30,
  `pg_dump`/`psql` binaries, timeout 3600), `health` thresholds (db ms 250; queue 50/200;
  failed jobs 1/10; heartbeat 5/15 min; backup 26/50 h; disk 20/10 %), `alert` (extra
  address `OPS_ALERT_EMAIL`, throttle 6 h). `.env.example` gains the `BACKUP_*`,
  `OPS_ALERT_EMAIL` keys and a note that the `s3` disk (`AWS_*`, `AWS_ENDPOINT` for R2/B2)
  is the backup target. `composer require league/flysystem-aws-s3-v3` (the base's `s3`
  disk has no driver installed).

## 2. Backups (`app/Client/Domain/Ops/Backup/`)
- `BackupCipher`: secretstream, 1 MiB chunks, magic `CENTRALBAK\x01` + 24-byte header;
  `encrypt(in, out)`, `decrypt(in, ?out)` (null = verify only); refuses wrong magic, wrong
  key, altered bytes, truncation (no FINAL), trailing data, empty plaintext. Key from config,
  32 bytes base64; `central:backup-key` prints a new one.
- `DatabaseDumper`: `pg_dump --clean --if-exists --no-owner --no-privileges` plain SQL
  (PGPASSWORD env, timeout), refuses a 0-byte dump; `restoreFrom(sql, database)` through
  `psql --set=ON_ERROR_STOP=1`; `runOnServer(sql)` against `postgres` for tests.
- `FileArchiver`: tar of `storage/app/private` (PharData), refuses an empty tree.
- `BackupRunner::run(?note)`: row in `backup_runs` (status running), prefix
  `{path}/{Y/m}/{Ymd-His}`, dump → encrypt → `writeStream` as `-db.sql.enc`, files as
  `-files.tar.enc`, read every artefact back and verify (bytes counted), status verified
  with sizes and duration, else failed with the error (rethrown); scratch under
  `storage/app/backup-scratch` (0700) always removed. `prune()`: keep the last 14 days,
  then the first verified run of each month for 12 months; failed rows older than 90 days
  dropped; never the newest verified. `BackupHealth::state()`: never | failing | stale |
  local | ok. `offsite` = disk driver not local, or `local_is_offsite`.
- `backup_runs(id, started_at, finished_at, status, disk, database_path, files_path,
  offsite, database_bytes, files_bytes, verified_bytes, duration_seconds, note, error,
  timestamps)`, index (status, started_at). Model `BackupRun`.
- `central:backup {--note=} {--no-prune}` (exit 1 on failure, warns when not off-site);
  `central:restore {--run=} {--into=} {--files} {--force}`: newest verified by default,
  confirmation unless `--force`, into the live database only with `--force` (the DEPLOY
  drill uses `--into=central_restore` after `createdb`), plaintext always removed.

## 3. Health and alerts (`app/Client/Domain/Ops/Health/`)
- `OpsHealth::checks()`: database (latency), redis (put/get probe), queue (size), failed
  jobs, scheduler heartbeat (cache key `ops:scheduler-heartbeat`), backup age (newest
  verified off-site run), disk (free % of `storage_path()`); each check catches its own
  exception as critical "not measurable". `OpsCheck`, `OpsStatus` enum (Healthy, Warning,
  Critical), `worst()`, `failing()`.
- `OpsAlerter::sweep()`: nothing critical → forget the throttle key; else `Cache::add`
  throttle (6 h; sent anyway when the cache itself is down) and mail `SystemAlertMessage`
  (text, company language) to active administrators plus `ops.alert.extra_email`, log a
  warning. `central:health {--alert}` prints every check, exit 0/1/2.

## 4. Ledger integrity (`app/Client/Domain/Ops/Integrity/LedgerIntegrity`)
- `findings()`: (a) `journal`: every active journal entry's lines balance; the trial
  balance of active lines balances; (b) `stock`: every `item_costs` row equals
  `CostEngine::replay` (qty and value), and every (item, warehouse) with active movements
  has a row; (c) `settlement`: for every document type with `paid_amount`/`payment_status`
  (sales and purchase invoices, returns, down payments, expense accruals, payroll entries,
  opening balances) the cache equals `SettlementService::paidAmount` and
  `PaymentStatus::derive`; (d) `reservations`: no (line, item, warehouse) sums below zero,
  no `reverses_id` reversed twice, and held ≤ on hand unless Allow Negative Stock.
  `IntegrityFinding(check, subject, detail)`. Read-only; nothing repaired.
- `central:integrity {--notify}`: prints `OK` or one `DRIFT [check] subject: detail` line
  each, exit 0/1; `--notify` mails `IntegrityFindingsMessage` to the same recipients when
  there are findings (once per run; the nightly run is the daily mail).

## 5. Launch readiness (`app/Client/Domain/Ops/Launch/`)
- Automatic checks: `environment` (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`
  https), `mail` (mailer not log/array), `company_identity` (name, address, NPWP in
  Preferences), `site_contact` (site contact values not the config placeholders:
  `example.com`, `0000`), `partners` (none named "Partner Name"), `price_list` (a published
  version), `staff_passwords` (no active user whose hash verifies "password"), `backup`
  (a verified off-site run), `integrity` (no findings), `two_factor`
  (`PreferensiKey::AdministratorTwoFactor` on), `first_invoice` (an approved sales invoice).
  Each wrapped so an exception is a failed check. Attested items: `pse`, `kbli`,
  `legal_reviewed`, `commercial_values`, `invoice_format`, `restore_drilled`.
- `launch_attestations(id, key unique, attested_by, attested_at, note, timestamps)`,
  `AttestationRecorder::attest(key, actor, note)` / `retract(key, actor, reason)`,
  administrators only, audited `launch_item_attested` / `launch_item_retracted`.
- Screen **Launch Readiness**: the list (automatic first), Attest (note required) and
  Retract (reason required) actions, navigation badge = outstanding count.
  `central:launch-check` exits 0 only when everything passes.

## 6. Operations screen (`client__operations`)
Health checks with status, the backup state and the last ten runs, integrity findings,
the throttle state; actions Run backup now (queued `RunBackup` job), Re-check (clears the
request memo). Administrator only (rights seeded ALL for Administrator, nobody else).

## 7. Deploy kit and documentation
- `deploy/Caddyfile` (`__DOMAIN__`, root `/srv/central/public`, php_fastcgi, encode,
  32 MB body, HSTS only: the app sends the rest), `deploy/supervisor/central-worker.conf`
  (2 × `queue:work redis --sleep=3 --tries=3 --max-time=3600`, user `central`),
  `deploy/logrotate/central`, `deploy/provision.sh <domain>` (user `central`, ufw, PHP 8.4
  + extensions, PostgreSQL 16, Redis `noeviction` 512 MB, Caddy, supervisor, cron
  `schedule:run`, `/srv/central`), `deploy/deploy.sh [--first]` (disk guard 1 GB, `down`,
  `git pull --ff-only`, composer, `npm ci && npm run build`, manifest assert, `migrate
  --force`, `storage:link --force`, stale cache files removed, `optimize` +
  `filament:optimize`, `queue:restart`, `up`, `central:launch-check` informational; on
  failure `up` only when the app can serve).
- `.github/workflows/deploy.yml`: on push to `production` and dispatch; job `tests` reuses
  `laravel.yml` (`workflow_call` trigger added: a CI edit, listed); job `deploy` with
  `environment: production`, SSH with `DEPLOY_HOST/USER/SSH_KEY/KNOWN_HOSTS`, runs
  `deploy/deploy.sh`. `laravel.yml` lint gains `bash -n deploy/*.sh`.
- `docs/DEPLOY.md` rewritten where it changes: paths `/srv/central`, user `central`,
  backups through `central:backup` to the `s3` disk, the restore drill with
  `central:restore --into`, the deploy script and workflow, the runbook "when something is
  wrong" (Redis down, scheduler silent, Vite manifest, truncated config cache), health and
  readiness commands.

## Base edits (listed)
`composer.json` (S3 driver), `.env.example` (keys), `.github/workflows/laravel.yml`
(`workflow_call`, lint of the deploy scripts), `docs/DEPLOY.md`. No PHP in the base.

## Files
- `app/Client/Modules/OpsModule.php`; `CentralScreen::{Operations,LaunchReadiness}`;
  `app/Client/config/ops.php`; migrations `2026_10_14_0001_backup_runs`,
  `0002_launch_attestations`; models `BackupRun`, `LaunchAttestation`.
- `app/Client/Domain/Ops/Backup/{BackupCipher,DatabaseDumper,FileArchiver,BackupRunner,
  BackupHealth}.php`, `Health/{OpsHealth,OpsCheck,OpsStatus,OpsAlerter}.php`,
  `Integrity/{LedgerIntegrity,IntegrityFinding}.php`, `Launch/{LaunchReadiness,LaunchCheck,
  AttestationRecorder}.php`; `app/Client/Jobs/RunBackup.php`; `app/Client/Console/{Backup,
  BackupKey,Restore,Health,Integrity,LaunchCheck}Command.php`; `app/Client/Mail/{SystemAlert,
  IntegrityFindings}Message.php` + text views.
- `app/Client/Filament/Pages/{Operations,LaunchReadiness}.php` + views.
- `deploy/*`, `.github/workflows/deploy.yml`, docs, roadmap row 6, notes in
  `docs/standard/_notes/settings.md`, `lang/id.json`.
- Tests `tests/Unit/Client/BackupCipherTest.php`, `tests/Feature/Client/Ops/{BackupTest,
  OpsHealthTest, OpsAlertTest, LedgerIntegrityTest, LaunchReadinessTest, OpsScreensTest,
  DeployKitTest}.php`.

## Order of work (TDD per step, commit per step)
1. Spec; commit.
2. Module, config, env, S3 driver; cipher + dumper + archiver + runner + health + commands;
   `BackupCipherTest`, `BackupTest` (restore into `central_restore_test`).
3. Health checks, heartbeat, alerter, mail, command; `OpsHealthTest`, `OpsAlertTest`.
4. Integrity sweep, command, mail; `LedgerIntegrityTest` (clean flow, each drift injected).
5. Launch readiness, attestations, screen, command; `LaunchReadinessTest`.
6. Operations screen; `OpsScreensTest`.
7. Deploy kit, workflow, DEPLOY.md; `DeployKitTest` (`bash -n`, YAML parses, placeholders).
8. Notes, `erp:standard`, i18n, full suite, Pint, build, roadmap row 6; commit; push.

## Verification
- `php artisan test`, `vendor/bin/pint --test`, `php artisan erp:standard --check`,
  `npm run build`, `bash -n deploy/*.sh`.
- Manual: `php artisan central:backup-key` → `.env`; `central:backup` writes two `.enc`
  files and a verified row; `createdb central_restore && php artisan central:restore
  --into=central_restore --force` loads the dump; `central:health` reports every check;
  stop Redis → `central:health` is critical and `--alert` mails once; `central:integrity` is
  OK on the demo company, DRIFT after `update item_costs set qty_on_hand = 999`;
  `central:launch-check` lists what is open; attest an item on the screen; `deploy.sh` runs
  end to end on a provisioned box.
