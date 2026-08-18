<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payments\VirtualAccountGateway;
use App\Domain\Payments\VirtualAccountProvisioner;
use App\Domain\Payments\XenditVirtualAccountGateway;
use App\Models\Company;
use App\Models\VirtualAccount;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Prove the Xendit wiring works on the machine it is actually deployed on.
 *
 * The test suite already proves the *logic* — a callback marks an order paid,
 * a redelivery does not double-credit, a bad token is refused. None of that
 * can tell you the four things that actually break on a first deployment, and
 * every one of them is invisible until a customer's money is involved:
 *
 *   1. The keys in `.env` are wrong, or are still the sandbox ones.
 *   2. The container is quietly handing out `LocalVirtualAccountGateway`,
 *      which mints plausible account numbers that no bank has ever heard of.
 *   3. Xendit's callback cannot reach this box at all — DNS, TLS, Caddy, a
 *      firewall, a reverse proxy stripping the token header.
 *   4. The callback arrives, is stored, and then sits there because no queue
 *      worker is running. Nothing surfaces this: the gateway got its 200 and
 *      considers the job done.
 *
 * (3) and (4) are why this posts a real HTTP request to the app's own public
 * URL rather than calling the controller in-process. Going out through DNS,
 * TLS and Caddy and back in is the whole point — an in-process call proves
 * nothing about the path a real callback takes.
 *
 * **The callback stage carries no money.** It sends a zero-amount payload, and
 * `ProcessXenditCallback` returns early on those without writing a payment
 * entry. That is deliberate rather than timid: what needs proving here is that
 * the request arrives, is stored, and gets picked up. Posting a fake payment
 * with an amount on it would mean a payment entry for money nobody sent, which
 * is precisely the invariant the webhook-only rule exists to protect.
 */
class XenditVerifyCommand extends Command
{
    protected $signature = 'xendit:verify
        {--company= : Company id to provision a VA for. Defaults to the first active one}
        {--bank= : Bank code. Defaults to the first in config(xendit.va_banks)}
        {--skip-callback : Configuration and API only; do not post to the public URL}';

    protected $description = 'Check the Xendit keys, binding, API and callback path on this machine';

    private bool $failed = false;

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>Xendit — memeriksa sambungan</>');
        $this->newLine();

        $live = $this->configuration();

        if ($this->failed) {
            return $this->verdict();
        }

        $this->binding();
        $this->api();
        $this->strayLocalAccounts();

        if ($this->option('skip-callback')) {
            $this->skip('Callback', 'dilewati atas permintaan (--skip-callback)');
        } elseif ($live) {
            /*
             * Not a safety blanket — a real refusal. On live keys this would
             * put a webhook_events row carrying an invented event id into the
             * table that decides what has already been processed. The event id
             * is the idempotency key for real money.
             */
            $this->skip('Callback', 'dilewati: kunci produksi, tidak boleh disuntik event palsu');
        } else {
            $this->callback();
        }

        return $this->verdict();
    }

    /** @return bool whether these are live keys */
    private function configuration(): bool
    {
        $secret = (string) config('xendit.secret_key');
        $token = (string) config('xendit.callback_token');
        $appUrl = (string) config('app.url');

        if ($secret === '') {
            $this->bad('Konfigurasi', 'XENDIT_SECRET_KEY kosong');
            $this->hint('Tanpa ini seluruh sistem memakai LocalVirtualAccountGateway: nomor VA');
            $this->hint('dibuat sendiri, terlihat wajar, dan tidak ada bank yang mengenalinya.');

            return false;
        }

        // Xendit's own prefixes. The distinction is worth reading out loud
        // because "we are live" and "we are pointed at the sandbox" look
        // identical from every screen in the application.
        $live = str_starts_with($secret, 'xnd_production_');
        $sandbox = str_starts_with($secret, 'xnd_development_');

        $mode = match (true) {
            $live => 'PRODUKSI — transfer sungguhan',
            $sandbox => 'sandbox',
            default => 'tidak dikenali (bukan xnd_production_ / xnd_development_)',
        };

        $this->ok('Konfigurasi', "kunci terpasang, mode: {$mode}");

        if (! $live && ! $sandbox) {
            $this->hint('Kunci Xendit biasanya diawali xnd_production_ atau xnd_development_.');
        }

        if ($token === '') {
            $this->bad('Callback token', 'XENDIT_CALLBACK_TOKEN kosong');
            $this->hint('Callback akan ditolak 401. Pesanan tidak akan pernah menjadi lunas,');
            $this->hint('dan uangnya tetap masuk ke rekening — jadi ini gagal diam-diam.');
        } else {
            $this->ok('Callback token', 'terpasang');
        }

        if (! str_starts_with($appUrl, 'https://')) {
            $this->bad('APP_URL', "bukan https: {$appUrl}");
            $this->hint('Xendit hanya mengirim callback ke https. Ini juga alamat yang dipakai');
            $this->hint('perintah ini untuk mengetes jalur callback.');
        } else {
            $this->ok('APP_URL', $appUrl);
        }

        return $live;
    }

    /**
     * Which gateway the container actually hands out.
     *
     * The binding is by config, so this is the one check that catches an
     * environment where the key is set but not visible to the running process
     * — a stale `config:cache` being the usual reason.
     */
    private function binding(): void
    {
        $gateway = app(VirtualAccountGateway::class);

        if ($gateway instanceof XenditVirtualAccountGateway) {
            $this->ok('Binding', 'VirtualAccountGateway → XenditVirtualAccountGateway');

            return;
        }

        $this->bad('Binding', 'VirtualAccountGateway → '.class_basename($gateway));
        $this->hint('Kunci ada di .env tapi tidak terbaca proses ini. Biasanya config lama:');
        $this->hint('jalankan `php artisan config:clear && php artisan config:cache`.');
    }

    /**
     * Does the secret key actually open the door?
     *
     * Provisioning rather than a read-only ping, because provisioning is the
     * only Xendit call this system ever makes.
     *
     * The catch is that `ensureFor()` is idempotent by design: a company that
     * already has a VA for this bank returns the stored row and never touches
     * the network. Reporting that as a pass would be the worst kind of green —
     * it says the keys work when nothing was asked of them. So this picks a
     * company that has no account yet, and when there is none it says so
     * instead of claiming a success it did not earn.
     */
    private function api(): void
    {
        $bank = (string) ($this->option('bank') ?: (config('xendit.va_banks')[0] ?? 'BCA'));
        $company = $this->company($bank);

        if ($company === null) {
            $this->skip('API', "setiap perusahaan aktif sudah punya VA {$bank} — tidak ada panggilan yang dites");
            $this->hint('Pakai --company= untuk perusahaan tertentu, atau tambahkan satu pelanggan');
            $this->hint('baru. Jalur ini belum terbukti sampai ada panggilan yang benar-benar keluar.');

            return;
        }

        try {
            $va = app(VirtualAccountProvisioner::class)->ensureFor($company, $bank);

            $this->ok('API', sprintf(
                '%s %s untuk %s',
                $va->bank_code,
                $va->account_number,
                $company->nama,
            ));
        } catch (Throwable $e) {
            $this->bad('API', $e->getMessage());
            $this->hint('401 berarti kunci salah atau tertukar sandbox/produksi.');
            $this->hint('Bank yang belum diaktifkan di dashboard Xendit juga ditolak di sini.');
        }
    }

    /**
     * Virtual accounts this system invented itself.
     *
     * `LocalVirtualAccountGateway` mints these whenever the key is unset, which
     * is exactly right on a laptop and a disaster if one survives into
     * production: the customer is handed an account number that looks like
     * every other one, and their transfer fails at the bank — or worse, lands
     * somewhere that has nothing to do with us. Nothing else in the system will
     * ever notice, because from our side the row is perfectly well-formed.
     *
     * They are recognisable by the external_id the local gateway writes.
     */
    private function strayLocalAccounts(): void
    {
        $stray = VirtualAccount::query()
            ->where('external_id', 'like', 'local-%')
            ->with('company')
            ->get();

        if ($stray->isEmpty()) {
            $this->ok('VA lokal', 'tidak ada');

            return;
        }

        $this->bad('VA lokal', "{$stray->count()} nomor VA dibuat sendiri, bukan oleh Xendit");

        foreach ($stray->take(5) as $va) {
            $this->hint("· {$va->account_number} ({$va->bank_code}) — {$va->company?->nama}");
        }

        $this->hint('Nomor ini tidak dikenal bank mana pun. Hapus barisnya lalu terbitkan ulang');
        $this->hint('lewat Xendit sebelum pelanggan memakainya — dan periksa apakah ada yang');
        $this->hint('sudah terlanjur diberikan.');
    }

    /**
     * The round trip: out through DNS, TLS and Caddy, and back in.
     *
     * Then wait for a worker to pick it up, because a callback that is stored
     * and never processed is the failure nothing else reports — Xendit already
     * had its 200 and will not send it again.
     */
    private function callback(): void
    {
        $url = rtrim((string) config('app.url'), '/').'/webhooks/xendit';
        $eventId = 'verify-'.Str::uuid()->toString();

        try {
            $response = Http::withHeaders(['x-callback-token' => (string) config('xendit.callback_token')])
                ->acceptJson()
                ->timeout(20)
                ->post($url, [
                    'id' => $eventId,
                    'event' => 'verification',
                    // Zero on purpose: proves the path, writes no money.
                    'amount' => 0,
                    'created' => now()->toIso8601String(),
                ]);
        } catch (Throwable $e) {
            $this->bad('Callback', $e->getMessage());
            $this->hint("Perintah ini memanggil {$url} dari mesin ini sendiri.");
            $this->hint('Kalau gagal di sini, callback Xendit juga tidak akan sampai.');

            return;
        }

        if ($response->status() === 401) {
            $this->bad('Callback', '401 — token ditolak');
            $this->hint('XENDIT_CALLBACK_TOKEN di .env harus sama persis dengan yang di');
            $this->hint('dashboard Xendit. Reverse proxy yang membuang header x-callback-token');
            $this->hint('memberi gejala yang sama.');

            return;
        }

        if ($response->failed()) {
            $this->bad('Callback', "{$response->status()} {$response->body()}");

            return;
        }

        $this->ok('Callback', "{$response->status()} dari {$url}");

        $event = WebhookEvent::query()
            ->where('gateway', 'xendit')
            ->where('event_id', $eventId)
            ->first();

        if ($event === null) {
            $this->bad('Penyimpanan', 'callback dijawab 200 tapi tidak tersimpan di webhook_events');

            return;
        }

        $this->ok('Penyimpanan', "webhook_events #{$event->id}");

        $this->worker($event);
    }

    /** Is anything actually draining the queue? */
    private function worker(WebhookEvent $event): void
    {
        $deadline = now()->addSeconds(20);

        while (now()->lessThan($deadline)) {
            if ($event->refresh()->isProcessed()) {
                $this->ok('Antrean', 'diproses pekerja antrean');

                return;
            }

            usleep(500_000);
        }

        $this->bad('Antrean', 'tersimpan tapi belum diproses setelah 20 detik');
        $this->hint('Tidak ada pekerja antrean yang mengambilnya. Callback yang sungguhan akan');
        $this->hint('berhenti di titik yang sama — dan Xendit sudah menerima 200, jadi tidak');
        $this->hint('akan dikirim ulang. Periksa supervisor dan `php artisan queue:work`.');
    }

    /** An active company that has no VA for this bank yet, so the call goes out. */
    private function company(string $bank): ?Company
    {
        $id = $this->option('company');

        if ($id !== null) {
            $company = Company::query()->find($id);

            if ($company === null) {
                $this->warn("  Perusahaan {$id} tidak ada.");
            }

            return $company;
        }

        return Company::query()
            ->where('status', Company::STATUS_ACTIVE)
            ->whereDoesntHave('virtualAccounts', fn ($q) => $q->where('bank_code', $bank))
            ->orderBy('id')
            ->first();
    }

    // --- output -------------------------------------------------------------

    private function ok(string $label, string $detail): void
    {
        $this->line(sprintf('  <fg=green>✓</> %-16s %s', $label, $detail));
    }

    private function bad(string $label, string $detail): void
    {
        $this->failed = true;
        $this->line(sprintf('  <fg=red>✗</> %-16s %s', $label, $detail));
    }

    private function skip(string $label, string $detail): void
    {
        $this->line(sprintf('  <fg=gray>–</> %-16s %s', $label, $detail));
    }

    private function hint(string $line): void
    {
        $this->line("    <fg=gray>{$line}</>");
    }

    private function verdict(): int
    {
        $this->newLine();

        if ($this->failed) {
            $this->line('  <fg=red;options=bold>Ada yang belum beres.</> Jangan buka pembayaran untuk pembeli dulu.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line('  <fg=green;options=bold>Sambungan Xendit siap.</>');
        $this->line('  <fg=gray>docs/DEPLOY.md punya sisa daftar sebelum go-live.</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
