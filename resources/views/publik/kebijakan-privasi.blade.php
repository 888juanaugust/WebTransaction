{{--
    Kebijakan Privasi — required under UU PDP 27/2022 before real users touch
    the system, and a prerequisite for PSE Lingkup Privat registration.

    The list of data categories is NOT written here. It renders from
    App\Support\Legal\DataInventory, which a test checks against the actual
    database schema — so adding a column that holds someone's phone number and
    forgetting to mention it fails the build instead of quietly making this page
    a false statement.

    Everything asserted on this page is true of the system as built: no
    analytics, no third-party trackers, no CDN webfonts, one outbound
    integration. Do not add a sentence here that the code does not support.
--}}
@extends('layouts.publik')

@section('judul', 'Kebijakan Privasi')
@section('deskripsi', 'Bagaimana ' . config('perusahaan.nama') . ' mengumpulkan, memakai, dan melindungi data pribadi sesuai UU No. 27 Tahun 2022.')

@php
    use App\Support\Legal\DataInventory;
    use Illuminate\Support\Carbon;

    $berlaku = Carbon::parse(config('legal.privasi.berlaku_sejak'));
    $email = config('legal.privasi.email');
    $jam = config('legal.privasi.batas_waktu_koreksi_jam');

    // UU PDP Pasal 5–15. Listed in full because "you have certain rights" is
    // not a disclosure — the reader has to be able to tell what to ask for.
    $hak = [
        ['Mendapat informasi', 'Mengetahui identitas kami, dasar hukum, tujuan, dan akuntabilitas pihak yang meminta data Anda.'],
        ['Melihat dan mendapat salinan', 'Meminta akses dan salinan data pribadi Anda yang kami simpan.'],
        ['Memperbaiki', 'Melengkapi, memperbarui, atau membetulkan data yang keliru.'],
        ['Menghapus', 'Meminta pengakhiran pemrosesan, penghapusan, atau pemusnahan data pribadi Anda.'],
        ['Menarik persetujuan', 'Menarik persetujuan atas pemrosesan yang memang didasarkan pada persetujuan Anda.'],
        ['Menolak keputusan otomatis', 'Menolak tindakan pengambilan keputusan yang semata-mata otomatis dan berdampak hukum bagi Anda.'],
        ['Menunda atau membatasi', 'Meminta penundaan atau pembatasan pemrosesan data pribadi Anda.'],
        ['Memindahkan data', 'Memperoleh dan mengirimkan data pribadi Anda kepada pengendali lain dalam format yang dapat dibaca sistem.'],
        ['Menggugat dan menuntut ganti rugi', 'Mengajukan gugatan dan menerima ganti rugi atas pelanggaran data pribadi Anda.'],
    ];
@endphp

@section('konten')

    <section class="border-b border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-3xl px-4 py-16">
            <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Kebijakan Privasi</h1>
            <p class="mt-4 text-lg text-slate-600">
                Bagaimana kami mengumpulkan, memakai, menyimpan, dan melindungi data pribadi
                dalam sistem pemesanan grosir kami.
            </p>
            <p class="mt-6 text-sm text-slate-500">
                Berlaku sejak {{ $berlaku->translatedFormat('j F Y') }} · Versi {{ config('legal.privasi.versi') }}
            </p>
        </div>
    </section>

    <article class="mx-auto max-w-3xl px-4 py-16 [&_h2]:mt-12 [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-slate-900
                    [&_h3]:mt-8 [&_h3]:font-semibold [&_h3]:text-slate-900
                    [&_p]:mt-4 [&_p]:leading-relaxed [&_p]:text-slate-600
                    [&_li]:leading-relaxed [&_li]:text-slate-600">

        <p class="!mt-0 rounded-lg border border-brand-200 bg-brand-50/60 p-5 !text-slate-700">
            Kebijakan ini disusun mengikuti <strong>Undang-Undang Nomor 27 Tahun 2022 tentang
            Pelindungan Data Pribadi</strong> (UU PDP) dan Peraturan Pemerintah Nomor 71 Tahun 2019
            tentang Penyelenggaraan Sistem dan Transaksi Elektronik.
        </p>

        <h2 id="pengendali">1. Siapa yang bertanggung jawab</h2>
        <p>
            Pengendali data pribadi dalam kebijakan ini adalah
            <strong>{{ config('perusahaan.nama') }}</strong>,
            beralamat di {{ config('perusahaan.kontak.alamat') }}@if (config('perusahaan.legal.nib')), dengan NIB {{ config('perusahaan.legal.nib') }}@endif.
        </p>
        <p>
            Pertanyaan, permintaan akses, koreksi, atau penghapusan data pribadi dapat dikirim ke
            <a class="font-semibold text-brand-600 hover:text-brand-700" href="mailto:{{ $email }}">{{ $email }}</a>
            atau melalui nomor {{ config('perusahaan.kontak.telepon') }}.
            @if (config('legal.privasi.dpo'))
                Pejabat Pelindungan Data Pribadi kami dapat dihubungi di {{ config('legal.privasi.dpo') }}.
            @endif
        </p>

        <h2 id="siapa">2. Untuk siapa kebijakan ini berlaku</h2>
        <p>
            Layanan kami ditujukan untuk <strong>pelaku usaha</strong> — bengkel, toko sparepart,
            dan distributor — bukan untuk pembeli eceran. Data yang kami olah sebagian besar adalah
            data perusahaan, yang bukan merupakan data pribadi menurut UU PDP.
        </p>
        <p>
            Namun sebagian di antaranya tetap merupakan data pribadi, dan kebijakan ini berlaku
            penuh atas bagian tersebut: nama narahubung di perusahaan pelanggan, akun masuk
            pengguna portal, akun staf kami, dan — bagi pelanggan yang berbentuk usaha perseorangan —
            NPWP serta alamat pajak, yang dalam hal itu melekat pada orang, bukan pada badan hukum.
        </p>

        <h2 id="jenis">3. Data apa yang kami olah</h2>
        <p>
            Daftar berikut disusun langsung dari struktur basis data sistem kami dan diperiksa
            secara otomatis setiap kali sistem dibangun, sehingga daftar ini tidak dapat menjadi
            usang tanpa diketahui.
        </p>

        @foreach (DataInventory::categories() as $kategori)
            <div class="mt-8 rounded-xl border border-slate-200 p-6">
                <h3 class="!mt-0 text-base">{{ $kategori['judul'] }}</h3>

                <p class="!mt-3 text-sm">{!! $kategori['isi'] !!}</p>

                <dl class="mt-5 space-y-3 border-t border-slate-100 pt-4 text-sm">
                    <div class="sm:flex sm:gap-4">
                        <dt class="shrink-0 font-semibold text-slate-500 sm:w-32">Tujuan</dt>
                        <dd class="text-slate-600">{{ $kategori['tujuan'] }}</dd>
                    </div>
                    <div class="sm:flex sm:gap-4">
                        <dt class="shrink-0 font-semibold text-slate-500 sm:w-32">Dasar hukum</dt>
                        <dd class="text-slate-600">{{ $kategori['dasar'] }}</dd>
                    </div>
                    <div class="sm:flex sm:gap-4">
                        <dt class="shrink-0 font-semibold text-slate-500 sm:w-32">Lama simpan</dt>
                        <dd class="text-slate-600">{{ $kategori['retensi'] }}</dd>
                    </div>
                </dl>
            </div>
        @endforeach

        <p>
            Kami <strong>tidak mengolah data pribadi yang bersifat spesifik</strong> sebagaimana
            dimaksud Pasal 4 ayat (2) UU PDP — data kesehatan, biometrik, genetika, catatan
            kejahatan, atau data anak. Kami juga tidak meminta nomor kartu kredit maupun data
            rekening pribadi Anda; pembayaran dilakukan melalui transfer ke Virtual Account.
        </p>

        <h2 id="sumber">4. Dari mana data itu kami peroleh</h2>
        <p>
            Sebagian besar berasal langsung dari Anda: saat mengajukan pembukaan akun grosir, saat
            memesan, dan saat berkomunikasi dengan tim kami. Sebagian lagi terbentuk dengan
            sendirinya saat Anda memakai sistem — catatan pesanan, jejak audit, dan waktu masuk
            terakhir. Data pembayaran kami terima dari penyedia gateway pembayaran ketika transfer
            Anda masuk.
        </p>
        <p>
            Kami tidak membeli data dari pihak ketiga dan tidak mengumpulkan data Anda dari sumber
            lain di luar itu.
        </p>

        <h2 id="pihak-ketiga">5. Kepada siapa data dibagikan</h2>
        <p>Kami tidak menjual data pribadi. Data dibagikan hanya kepada pihak berikut:</p>
        <ul class="mt-4 space-y-3">
            <li class="rounded-lg border border-slate-200 p-4">
                <strong class="text-slate-900">Penyedia gateway pembayaran</strong> — untuk
                menerbitkan Virtual Account atas nama perusahaan Anda dan mencocokkan pembayaran
                yang masuk. Yang dibagikan adalah identitas perusahaan pelanggan dan nilai tagihan.
            </li>
            <li class="rounded-lg border border-slate-200 p-4">
                <strong class="text-slate-900">Direktorat Jenderal Pajak</strong> — identitas wajib
                pajak dan nilai transaksi, sebatas yang wajib dilaporkan dalam rangka penerbitan
                faktur pajak dan pelaporan Pajak Pertambahan Nilai.
            </li>
            <li class="rounded-lg border border-slate-200 p-4">
                <strong class="text-slate-900">Jasa pengiriman</strong> — nama penerima, alamat
                kirim, dan nomor telepon, sebatas yang diperlukan agar barang sampai.
            </li>
            <li class="rounded-lg border border-slate-200 p-4">
                <strong class="text-slate-900">Aparat penegak hukum atau instansi berwenang</strong>
                — hanya atas permintaan yang sah menurut peraturan perundang-undangan.
            </li>
        </ul>

        <h2 id="transfer">6. Data tidak dikirim ke luar negeri</h2>
        <p>
            Sistem dan basis data kami dijalankan pada server yang berlokasi di
            <strong>{{ config('legal.privasi.lokasi_server') }}</strong>. Kami tidak memindahkan
            data pribadi ke luar wilayah Republik Indonesia, sehingga ketentuan Pasal 56 UU PDP
            mengenai transfer data pribadi ke luar negeri tidak berlaku dalam layanan ini.
        </p>

        <h2 id="cookie">7. Cookie dan pelacakan</h2>
        <p>
            Kami memasang <strong>dua cookie, dan keduanya bersifat teknis</strong>. Tidak ada
            cookie pelacakan, cookie iklan, maupun cookie profil di situs ini.
        </p>
        <ul class="mt-4 space-y-3">
            <li class="rounded-lg border border-slate-200 p-4">
                <strong class="text-slate-900 font-mono text-sm">{{ config('session.cookie') }}</strong>
                <p class="!mt-1 text-sm">
                    Menjaga sesi Anda. Isinya berupa penanda sesi terenkripsi, bukan identitas Anda.
                    Berlaku {{ config('session.lifetime') }} menit dan tidak dapat dibaca oleh skrip
                    di peramban.
                </p>
            </li>
            <li class="rounded-lg border border-slate-200 p-4">
                <strong class="text-slate-900 font-mono text-sm">XSRF-TOKEN</strong>
                <p class="!mt-1 text-sm">
                    Melindungi formulir dari pemalsuan permintaan lintas situs, agar tidak ada situs
                    lain yang bisa mengirim perintah atas nama Anda.
                </p>
            </li>
        </ul>
        <p>
            Keduanya diperlukan agar situs berfungsi, karena itu tidak tersedia pilihan untuk
            menolaknya selain dengan tidak memakai situs ini. Keduanya tidak dipakai untuk mengenali
            Anda antar kunjungan maupun antar situs.
        </p>
        <p>
            Kami <strong>tidak memasang layanan analitik, piksel iklan, tombol media sosial, maupun
            pelacak pihak ketiga</strong>. Seluruh berkas halaman ini — termasuk huruf dan gambar —
            dilayani dari server kami sendiri, tanpa jaringan pengiriman konten pihak ketiga.
            Kunjungan Anda ke halaman publik kami karena itu tidak diketahui pihak mana pun selain
            kami.
        </p>

        <h2 id="keamanan">8. Bagaimana data diamankan</h2>
        <ul class="mt-4 list-disc space-y-2 pl-5">
            <li>Seluruh lalu lintas ke sistem dienkripsi dengan TLS.</li>
            <li>Kata sandi disimpan dalam bentuk hash satu arah — kami tidak dapat membacanya, dan tidak akan pernah meminta Anda menyebutkannya.</li>
            <li>Staf dan pelanggan masuk melalui dua sistem otentikasi yang terpisah, sehingga akun pelanggan tidak memiliki identitas apa pun di panel staf.</li>
            <li>Setiap pelanggan hanya dapat melihat data perusahaannya sendiri. Pembatasan ini melekat pada kueri basis data, bukan pada tampilan.</li>
            <li>Akses staf dibatasi menurut peran. Staf gudang, misalnya, tidak dapat melihat harga maupun data kredit pelanggan.</li>
            <li>Setiap tindakan yang berdampak pada uang dicatat dalam jejak audit yang hanya bisa ditambah, tidak bisa diubah atau dihapus.</li>
            <li>Basis data dicadangkan secara berkala dalam bentuk terenkripsi.</li>
        </ul>

        <h2 id="insiden">9. Jika terjadi kebocoran data</h2>
        <p>
            Apabila terjadi kegagalan pelindungan data pribadi, kami akan menyampaikan
            pemberitahuan tertulis kepada Anda dan kepada lembaga yang berwenang paling lambat
            <strong>{{ config('legal.privasi.batas_waktu_pemberitahuan_insiden_jam') }} jam</strong>
            sejak diketahui, sebagaimana diwajibkan Pasal 46 UU PDP. Pemberitahuan itu akan memuat
            data pribadi yang terungkap, kapan dan bagaimana hal itu terjadi, serta penanganan dan
            pemulihan yang kami lakukan.
        </p>

        <h2 id="hak">10. Hak Anda atas data pribadi Anda</h2>
        <p>UU PDP memberi Anda hak-hak berikut, dan kami menghormatinya:</p>
        <div class="mt-6 space-y-3">
            @foreach ($hak as [$judul, $isi])
                <div class="rounded-lg border border-slate-200 p-4">
                    <p class="!mt-0 font-semibold text-slate-900">{{ $judul }}</p>
                    <p class="!mt-1 text-sm">{{ $isi }}</p>
                </div>
            @endforeach
        </div>

        <h3>Cara menggunakan hak tersebut</h3>
        <p>
            Kirim permintaan ke
            <a class="font-semibold text-brand-600 hover:text-brand-700" href="mailto:{{ $email }}">{{ $email }}</a>
            dari alamat email yang terdaftar pada akun Anda, atau hubungi kami melalui nomor di atas.
            Permintaan pembetulan data akan kami tindak lanjuti paling lambat
            <strong>{{ $jam }} jam</strong> sejak diterima, sesuai Pasal 37 UU PDP. Kami tidak
            memungut biaya atas permintaan yang wajar.
        </p>

        <h3>Batasan yang perlu Anda ketahui</h3>
        <p>
            Hak penghapusan tidak berlaku mutlak. Faktur, catatan pembayaran, dan pembukuan yang
            menyertainya wajib kami simpan selama sepuluh tahun menurut ketentuan perpajakan dan
            ketentuan mengenai dokumen perusahaan. Selama kewajiban itu berjalan, data tersebut
            tidak dapat kami hapus atas permintaan — tetapi pemakaiannya kami batasi hanya untuk
            memenuhi kewajiban tersebut.
        </p>

        <h2 id="anak">11. Anak-anak</h2>
        <p>
            Layanan ini ditujukan untuk pelaku usaha dan tidak dimaksudkan bagi anak. Kami tidak
            dengan sengaja mengumpulkan data pribadi anak. Jika Anda mengetahui hal itu terjadi,
            hubungi kami dan data tersebut akan kami hapus.
        </p>

        <h2 id="perubahan">12. Perubahan kebijakan ini</h2>
        <p>
            Kebijakan ini dapat kami perbarui. Setiap versi mencantumkan tanggal berlakunya di
            bagian atas halaman. Bila perubahannya bersifat mendasar, kami akan memberitahukannya
            kepada pelanggan terdaftar melalui email sebelum perubahan itu berlaku.
        </p>

        <h2 id="pengaduan">13. Pengaduan</h2>
        <p>
            Sampaikan keberatan Anda lebih dahulu kepada kami — sebagian besar persoalan selesai di
            situ. Jika Anda menilai penanganan kami belum memadai, Anda berhak mengadu kepada
            lembaga yang berwenang di bidang pelindungan data pribadi, dan berhak mengajukan
            gugatan sebagaimana diatur Pasal 12 UU PDP.
        </p>

        <div class="mt-14 rounded-xl border border-slate-200 bg-slate-50 p-6">
            <p class="!mt-0 text-sm">
                Kebijakan ini melengkapi
                <a class="font-semibold text-brand-600 hover:text-brand-700" href="{{ route('publik.syarat') }}">Syarat Penjualan</a>
                kami, yang mengatur hubungan dagang antara kami dan pelanggan terdaftar.
            </p>
        </div>

    </article>

@endsection
