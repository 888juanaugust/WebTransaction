<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\Company;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * How far one customer is from actually using the portal.
 *
 * Every step is derived from the customer's real state on every read —
 * nothing here is a tickbox anyone can set, for the same reason the launch
 * checklist works that way: a stored "done" outlives its own truth. Take
 * the price tier off a customer and the harga step un-ticks itself.
 *
 * The steps are the pilot's spine (docs/PILOT.md): a customer is onboarded
 * when their data is complete, staff have approved them with a real credit
 * limit, a team answers for them, their prices resolve, a portal login
 * exists, the buyer has actually signed in, and a first order has gone
 * through — the last two being the only proof the first five worked.
 */
class KesiapanOnboarding
{
    /** @return list<LangkahOnboarding> */
    public function langkah(Company $company): array
    {
        return [
            $this->dataKontak($company),
            $this->dataPajak($company),
            $this->disetujui($company),
            $this->limitKredit($company),
            $this->tim($company),
            $this->harga($company),
            $this->akunPortal($company),
            $this->masukPertama($company),
            $this->orderPertama($company),
        ];
    }

    public function selesai(Company $company): int
    {
        return count(array_filter($this->langkah($company), fn ($l) => $l->selesai));
    }

    public function total(): int
    {
        return 9;
    }

    private function dataKontak(Company $company): LangkahOnboarding
    {
        $kosong = array_keys(array_filter([
            'alamat kirim' => blank($company->alamat_kirim),
            'kota' => blank($company->kota),
            'telepon' => blank($company->telepon),
            'email' => blank($company->email),
        ]));

        return $kosong === []
            ? LangkahOnboarding::selesai('data_kontak', 'Data kontak lengkap',
                'Alamat kirim, kota, telepon dan email terisi.')
            : LangkahOnboarding::belum('data_kontak', 'Data kontak lengkap',
                'Belum terisi: '.implode(', ', $kosong).'.');
    }

    /**
     * Needed for a faktur pajak, not for trading. A non-PKP bengkel can order
     * and pay without one — the step stays visible so nobody discovers the
     * gap in the month-end Coretax export instead of during onboarding.
     */
    private function dataPajak(Company $company): LangkahOnboarding
    {
        $lengkap = filled($company->npwp)
            && filled($company->nama_wajib_pajak)
            && filled($company->alamat_pajak);

        return $lengkap
            ? LangkahOnboarding::selesai('data_pajak', 'Data pajak terisi',
                'NPWP, nama wajib pajak dan alamat pajak terisi.')
            : LangkahOnboarding::belum('data_pajak', 'Data pajak terisi',
                'NPWP/nama wajib pajak/alamat pajak belum lengkap. Boleh jalan '
                .'tanpa ini bila pelanggan non-PKP — tetapi tanpa faktur pajak.');
    }

    private function disetujui(Company $company): LangkahOnboarding
    {
        return $company->isActive()
            ? LangkahOnboarding::selesai('disetujui', 'Akun disetujui',
                'Status aktif'.($company->approved_at ? ', disetujui '.$company->approved_at->format('d/m/Y') : '').'.')
            : LangkahOnboarding::belum('disetujui', 'Akun disetujui',
                'Status masih '.$company->status.'. Setujui dari antrean '
                .'"Akun menunggu persetujuan" di dasbor.');
    }

    private function limitKredit(Company $company): LangkahOnboarding
    {
        return $company->credit_limit_rupiah > 0
            ? LangkahOnboarding::selesai('limit_kredit', 'Limit kredit ditetapkan',
                'Limit kredit terpasang — akun bisa berutang.')
            : LangkahOnboarding::belum('limit_kredit', 'Limit kredit ditetapkan',
                'Limit masih nol. Penjualan berjalan di atas kredit; limit nol '
                .'berarti order pertama pasti tertolak.');
    }

    private function tim(Company $company): LangkahOnboarding
    {
        $kosong = array_keys(array_filter([
            'sales' => $company->sales_user_id === null,
            'marketing' => $company->marketing_user_id === null,
        ]));

        return $kosong === []
            ? LangkahOnboarding::selesai('tim', 'Tim penanggung jawab terpasang',
                'Sales dan marketing sudah duduk — ada kursi yang menyetujui order ini.')
            : LangkahOnboarding::belum('tim', 'Tim penanggung jawab terpasang',
                'Kursi kosong: '.implode(', ', $kosong).'. Owner memasang tim '
                .'lewat layar Pelanggan; tanpa marketing, tidak ada yang bisa '
                .'menyetujui ordernya.');
    }

    private function harga(Company $company): LangkahOnboarding
    {
        return $company->price_tier_id !== null
            ? LangkahOnboarding::selesai('harga', 'Tingkat harga dipilih',
                'Harga pelanggan ini akan terselesaikan dari tingkatnya.')
            : LangkahOnboarding::belum('harga', 'Tingkat harga dipilih',
                'Belum ada tingkat harga — katalog portal tidak bisa menampilkan '
                .'harga untuk pelanggan ini.');
    }

    private function akunPortal(Company $company): LangkahOnboarding
    {
        $aktif = $company->customerUsers()->where('is_active', true)->count();

        return $aktif > 0
            ? LangkahOnboarding::selesai('akun_portal', 'Akun portal dibuat',
                $aktif.' akun aktif; undangan atur-kata-sandi terkirim saat dibuat.')
            : LangkahOnboarding::belum('akun_portal', 'Akun portal dibuat',
                'Belum ada login. Buat dari layar Pelanggan → Akun portal '
                .'pelanggan; pembeli menerima email untuk mengatur kata sandinya.');
    }

    private function masukPertama(Company $company): LangkahOnboarding
    {
        $terakhir = $company->customerUsers()
            ->whereNotNull('last_login_at')
            ->max('last_login_at');

        return $terakhir !== null
            ? LangkahOnboarding::selesai('masuk_pertama', 'Pembeli sudah masuk',
                'Terakhir masuk '.Carbon::parse($terakhir)->format('d/m/Y H:i').'.')
            : LangkahOnboarding::belum('masuk_pertama', 'Pembeli sudah masuk',
                'Belum pernah masuk. Undangan mungkin tersangkut di spam — kirim '
                .'ulang dari layar akun portal, atau dampingi lewat telepon.');
    }

    /**
     * Cross-region on purpose: a split books pieces in other regions'
     * documents, and "has this customer ordered" is a question about the
     * customer, not about whichever region the reader is pinned to.
     */
    private function orderPertama(Company $company): LangkahOnboarding
    {
        $ada = Order::query()
            ->withoutGlobalScope('region')
            ->where('company_id', $company->id)
            ->where('status', '!=', 'draft')
            ->exists();

        return $ada
            ? LangkahOnboarding::selesai('order_pertama', 'Order pertama diajukan',
                'Sudah ada order yang melewati draft — jalurnya terbukti hidup.')
            : LangkahOnboarding::belum('order_pertama', 'Order pertama diajukan',
                'Belum ada order melewati draft. Dampingi pesanan pertama — '
                .'lewat portal oleh pembeli, atau diketik sales bersama mereka.');
    }
}
