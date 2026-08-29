{{--
    Syarat Penjualan — the written terms CLAUDE.md requires before launch,
    covering credit terms, late payment, returns and delivery.

    B2B, not consumer terms. Buyers are registered businesses buying for resale
    or for their own workshop, so this document is written between merchants.

    Every clause about how an order behaves describes what OrderStateMachine
    actually does. The reservation window is read from the same setting the
    release job obeys, so the terms cannot promise a window the software does
    not honour. If a clause here and the code disagree, the code is what the
    customer experiences — fix one or the other, not this comment.
--}}
@extends('layouts.publik')

{{-- A legal instrument under Indonesian law: Bahasa Indonesia, on an English site. --}}
@section('lang', 'id')

@section('judul', 'Syarat Penjualan')
@section('deskripsi', 'Syarat dan ketentuan penjualan grosir ' . config('perusahaan.nama') . ' — termin kredit, pembayaran, pengiriman, dan pengembalian barang.')

@php
    use Illuminate\Support\Carbon;

    $berlaku = Carbon::parse(config('legal.syarat.berlaku_sejak'));
    $denda = config('legal.syarat.denda_persen_per_bulan');
    $klaim = config('legal.syarat.batas_klaim_hari');
    $reservasi = config('legal.syarat.kadaluarsa_reservasi_jam');
@endphp

@section('konten')

    <section class="border-b border-line">
        <div class="mx-auto max-w-3xl px-4 pt-16 pb-12 sm:pt-24 sm:pb-16">
            <h1 class="text-4xl font-semibold tracking-[-0.03em] text-ink sm:text-5xl">Syarat Penjualan</h1>
            <p class="mt-4 text-lg leading-relaxed text-ink-muted">
                Ketentuan yang berlaku atas setiap penjualan grosir dari
                {{ config('perusahaan.nama') }} kepada pelanggan terdaftar.
            </p>
            <p class="mt-6 text-sm text-ink-faint">
                Berlaku sejak {{ $berlaku->translatedFormat('j F Y') }} · Versi {{ config('legal.syarat.versi') }}
            </p>
        </div>
    </section>

    <article class="mx-auto max-w-3xl px-4 py-16 [&_h2]:mt-12 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:tracking-tight [&_h2]:text-ink
                    [&_h3]:mt-8 [&_h3]:font-semibold [&_h3]:text-ink
                    [&_p]:mt-4 [&_p]:leading-relaxed [&_p]:text-ink-muted
                    [&_li]:leading-relaxed [&_li]:text-ink-muted">

        <p class="!mt-0 rounded-card border border-brand-200 bg-brand-50/60 p-5 !text-ink">
            Syarat ini berlaku antara pelaku usaha. Pembeli kami adalah bengkel, toko sparepart,
            dan distributor yang membeli untuk dijual kembali atau untuk keperluan usahanya —
            <strong>bukan konsumen akhir</strong>. Dengan mengajukan pesanan, pembeli menyatakan
            bertindak dalam rangka usahanya dan menyetujui syarat ini.
        </p>

        <h2 id="definisi">1. Pengertian</h2>
        <ul class="mt-4 list-disc space-y-2 pl-5">
            <li><strong>Penjual</strong> — {{ config('perusahaan.nama') }}.</li>
            <li><strong>Pembeli</strong> — badan usaha yang akunnya telah kami setujui.</li>
            <li><strong>Pesanan</strong> — permintaan pembelian yang diajukan melalui portal pelanggan, melalui tim penjualan kami, atau tertulis.</li>
            <li><strong>Faktur</strong> — tagihan yang kami terbitkan atas pesanan yang telah dikonfirmasi.</li>
            <li><strong>Surat jalan</strong> — dokumen pengantar barang yang ditandatangani penerima.</li>
        </ul>

        <h2 id="akun">2. Pembukaan akun</h2>
        <p>
            Tidak ada pendaftaran mandiri. Akun grosir terbentuk setelah kami memverifikasi
            legalitas usaha pembeli dan menyepakati limit kredit serta termin pembayaran. Kami
            berhak menolak pengajuan akun tanpa menyebutkan alasan.
        </p>
        <p>
            Pembeli wajib menjaga kerahasiaan akun masuk portalnya dan bertanggung jawab atas
            seluruh pesanan yang diajukan melalui akun tersebut. Beri tahu kami segera jika akun
            diduga dipakai pihak yang tidak berhak.
        </p>

        <h2 id="harga">3. Harga</h2>
        <p>
            Harga bersifat khusus per pelanggan dan tidak ditampilkan kepada umum. Harga yang
            berlaku adalah harga yang tercantum pada saat pesanan <strong>dikonfirmasi</strong>,
            bukan pada saat pesanan diajukan.
        </p>
        <p>
            Pada saat konfirmasi, harga satuan, diskon, DPP, dan PPN untuk setiap baris pesanan
            disalin dan disimpan pada pesanan tersebut. Perubahan daftar harga sesudahnya tidak
            mengubah pesanan yang sudah dikonfirmasi maupun faktur yang sudah terbit.
        </p>
        <p>
            Seluruh harga dinyatakan dalam Rupiah dan <strong>belum termasuk PPN</strong>, kecuali
            dinyatakan lain. PPN dihitung per baris sesuai ketentuan yang berlaku dan ditambahkan
            pada faktur.
        </p>

        <h2 id="pesanan">4. Pesanan dan konfirmasi</h2>
        <p>
            Pesanan yang diajukan pembeli merupakan <strong>penawaran</strong>, bukan perjanjian
            yang mengikat. Perjanjian jual beli baru terbentuk ketika kami mengonfirmasi pesanan.
            Kami dapat menolak atau menyesuaikan pesanan, antara lain karena stok tidak mencukupi,
            limit kredit terlampaui, atau tagihan sebelumnya belum diselesaikan.
        </p>
        <p>
            Saat dikonfirmasi, stok disisihkan untuk pesanan tersebut. Apabila dalam
            <strong>{{ $reservasi }} jam</strong> pesanan belum ditagihkan, penyisihan stok itu
            dilepas kembali secara otomatis dan pesanan dapat kedaluwarsa.
        </p>
        <p>
            Pembatalan oleh pembeli atas pesanan yang telah dikonfirmasi hanya dapat dilakukan
            dengan persetujuan tertulis kami, dan tidak dapat dilakukan setelah barang dikirim.
        </p>

        <h2 id="kredit">5. Limit kredit dan termin pembayaran</h2>
        <p>
            Setiap pembeli memiliki limit kredit dan termin pembayaran yang disepakati saat akun
            disetujui. Pesanan yang membuat total tagihan berjalan melampaui limit kredit tidak
            akan dikonfirmasi sampai sebagian tagihan diselesaikan, kecuali kami menyetujui
            pelampauan tersebut secara tertulis.
        </p>
        <p>
            Kami dapat meninjau, menurunkan, atau menangguhkan limit kredit sewaktu-waktu, terutama
            bila terdapat tunggakan. Peninjauan itu tidak menghapus kewajiban atas tagihan yang
            sudah berjalan.
        </p>

        <h2 id="pembayaran">6. Pembayaran</h2>
        <p>
            Pembayaran dilakukan melalui <strong>transfer ke rekening perusahaan kami</strong>
            yang tercantum pada setiap faktur, atau secara tunai maupun bilyet giro melalui
            perwakilan sales kami.
        </p>
        <p>
            Pembayaran dianggap diterima pada saat dana masuk dan dikonfirmasi oleh tim keuangan
            kami — bukan pada saat bukti transfer dikirimkan. Bilyet giro dianggap lunas pada
            saat dananya cair, bukan pada saat bilyet diserahkan.
        </p>
        <p>
            Pembayaran wajib dilakukan penuh sesuai nilai faktur, tanpa pemotongan, pengurangan,
            atau perjumpaan utang, kecuali disepakati tertulis. Bila pembeli memiliki lebih dari
            satu faktur terbuka, pembayaran diperhitungkan terhadap faktur yang paling lama jatuh
            tempo, kecuali pembeli menyatakan lain.
        </p>

        <h3>Keterlambatan</h3>
        <p>
            Atas tagihan yang lewat jatuh tempo, kami berhak mengenakan denda keterlambatan sebesar
            <strong>{{ $denda }}% per bulan</strong> dari jumlah tertunggak, dihitung harian sejak
            tanggal jatuh tempo sampai pembayaran diterima.
        </p>
        <p>Selama terdapat tunggakan, kami juga berhak:</p>
        <ul class="mt-4 list-disc space-y-2 pl-5">
            <li>menahan pengiriman atas pesanan yang sedang berjalan;</li>
            <li>menolak konfirmasi pesanan baru;</li>
            <li>menangguhkan atau menurunkan limit kredit; dan</li>
            <li>menagih seluruh tagihan yang belum jatuh tempo untuk dibayar seketika.</li>
        </ul>
        <p>
            Biaya penagihan yang wajar, termasuk biaya jasa hukum, menjadi beban pembeli apabila
            penagihan harus ditempuh melalui pihak ketiga atau jalur hukum.
        </p>

        <h2 id="pajak">7. Faktur dan faktur pajak</h2>
        <p>
            Faktur diterbitkan atas pesanan yang telah dikonfirmasi dan dikirimkan kepada pembeli.
            Faktur pajak diterbitkan terpisah sesuai ketentuan perpajakan yang berlaku, berdasarkan
            NPWP, nama wajib pajak, dan alamat pajak yang terdaftar pada akun pembeli.
        </p>
        <p>
            Pembeli wajib memastikan data perpajakannya benar dan memberitahukan perubahannya
            sebelum pesanan dikonfirmasi. Koreksi faktur pajak akibat data yang keliru dari pembeli
            menjadi tanggung jawab pembeli.
        </p>

        <h2 id="pengiriman">8. Pengiriman dan penyerahan risiko</h2>
        <p>
            Barang dikirim ke alamat kirim yang terdaftar, disertai surat jalan. Perkiraan waktu
            kirim yang kami sampaikan adalah perkiraan, bukan jaminan, dan keterlambatan pengiriman
            tidak dengan sendirinya membatalkan pesanan.
        </p>
        <p>
            <strong>Risiko atas barang beralih kepada pembeli pada saat barang diserahkan</strong>
            kepada pembeli atau kepada pengangkut yang ditunjuk pembeli. Pembeli atau wakilnya wajib
            memeriksa dan menandatangani surat jalan pada saat penerimaan.
        </p>
        <p>
            <strong>Hak milik atas barang tetap berada pada kami sampai seluruh tagihan atas barang
            tersebut dibayar lunas.</strong> Sebelum itu, pembeli menyimpan barang tersebut sebagai
            barang milik kami, meskipun pembeli tetap boleh menjualnya kembali dalam kegiatan usaha
            sehari-hari.
        </p>

        <h2 id="klaim">9. Klaim, pengembalian, dan garansi</h2>
        <h3>Kekurangan, salah kirim, dan kerusakan yang terlihat</h3>
        <p>
            Klaim atas jumlah yang kurang, barang yang tidak sesuai pesanan, atau kerusakan yang
            terlihat wajib disampaikan paling lambat <strong>{{ $klaim }} hari kerja</strong>
            setelah barang diterima, disertai nomor surat jalan dan foto. Lewat batas itu barang
            dianggap diterima dalam keadaan baik dan sesuai.
        </p>

        <h3>Pengembalian barang</h3>
        <p>Barang hanya dapat dikembalikan bila kami menyetujuinya lebih dahulu secara tertulis, dan hanya bila barang:</p>
        <ul class="mt-4 list-disc space-y-2 pl-5">
            <li>masih dalam kemasan asli dan belum dipasang atau dipakai;</li>
            <li>disertai salinan surat jalan atau faktur; dan</li>
            <li>bukan barang pesanan khusus atau barang yang dipesan atas permintaan tertentu pembeli.</li>
        </ul>
        <p>
            Barang yang dikembalikan tanpa persetujuan tertulis kami tidak akan diproses. Kami dapat
            mengenakan biaya penanganan atas pengembalian yang bukan disebabkan kesalahan kami.
        </p>

        <h3>Cacat produksi</h3>
        <p>
            Untuk barang yang mengandung cacat produksi, berlaku ketentuan garansi dari prinsipal
            atau pabrikan merk yang bersangkutan. Kami membantu meneruskan klaim garansi tersebut,
            namun lingkup dan jangka waktunya ditentukan oleh prinsipal, bukan oleh kami.
        </p>
        <p>
            Garansi tidak berlaku atas kerusakan akibat pemasangan yang keliru, pemakaian di luar
            peruntukan, kecelakaan, modifikasi, atau keausan wajar.
        </p>

        <h2 id="tanggung-jawab">10. Batasan tanggung jawab</h2>
        <p>
            Tanggung jawab kami atas suatu pesanan dibatasi paling banyak sebesar nilai barang yang
            bersangkutan sebagaimana tercantum dalam faktur.
        </p>
        <p>
            Kami tidak bertanggung jawab atas kerugian tidak langsung — antara lain kehilangan
            keuntungan, kehilangan pelanggan, waktu henti kendaraan, atau tuntutan dari pihak ketiga
            kepada pembeli — kecuali dalam hal kesengajaan atau kelalaian berat di pihak kami, atau
            sepanjang pembatasan itu tidak diperkenankan oleh peraturan perundang-undangan.
        </p>

        <h2 id="keadaan-kahar">11. Keadaan kahar</h2>
        <p>
            Kami tidak dianggap lalai atas keterlambatan atau kegagalan pelaksanaan yang disebabkan
            keadaan di luar kendali wajar kami — antara lain bencana alam, kebakaran, wabah,
            kerusuhan, pemogokan, gangguan pasokan atau pengangkutan, gangguan jaringan listrik atau
            telekomunikasi, serta perubahan peraturan. Kewajiban pembayaran atas barang yang sudah
            diterima tidak ditangguhkan oleh keadaan tersebut.
        </p>

        <h2 id="data">12. Data pribadi</h2>
        <p>
            Pengolahan data pribadi dalam rangka syarat ini tunduk pada
            <a class="font-semibold text-brand-600 hover:text-brand-700" href="{{ route('publik.privasi') }}">Kebijakan Privasi</a>
            kami.
        </p>

        <h2 id="perubahan">13. Perubahan syarat</h2>
        <p>
            Kami dapat mengubah syarat ini. Versi yang berlaku atas suatu pesanan adalah versi yang
            tercantum pada halaman ini pada saat pesanan dikonfirmasi. Perubahan yang bersifat
            mendasar akan kami beritahukan kepada pelanggan terdaftar sebelum berlaku.
        </p>

        <h2 id="hukum">14. Hukum yang berlaku dan penyelesaian sengketa</h2>
        <p>
            Syarat ini tunduk pada hukum Republik Indonesia. Pemesanan, konfirmasi, dan dokumen yang
            diterbitkan melalui sistem ini dilakukan secara elektronik, dan para pihak sepakat bahwa
            catatan elektronik tersebut merupakan alat bukti yang sah sesuai ketentuan mengenai
            informasi dan transaksi elektronik.
        </p>
        <p>
            Setiap perselisihan diupayakan diselesaikan secara musyawarah lebih dahulu. Apabila
            tidak tercapai kesepakatan dalam 30 hari, para pihak sepakat menyelesaikannya melalui
            <strong>{{ config('legal.syarat.forum_sengketa') }}</strong>.
        </p>

        <h2 id="kontak">15. Hubungi kami</h2>
        <p>
            {{ config('perusahaan.nama') }}<br>
            {{ config('perusahaan.kontak.alamat') }}<br>
            {{ config('perusahaan.kontak.telepon') }} ·
            <a class="font-semibold text-brand-600 hover:text-brand-700" href="mailto:{{ config('perusahaan.kontak.email') }}">{{ config('perusahaan.kontak.email') }}</a><br>
            {{ config('perusahaan.kontak.jam_operasional') }}
        </p>

    </article>

@endsection
