<?php

declare(strict_types=1);

namespace App\Client\Site;

use App\Domain\Company\CompanyIdentity;
use App\Domain\Sales\CreditCheck;
use Illuminate\Support\Carbon;

/**
 * The two legal pages, as structured text: Kebijakan Privasi (UU PDP
 * 27/2022) and Syarat Penjualan (the written terms of sale). Instruments
 * under Indonesian law, so they are written in Bahasa Indonesia only and
 * never translated. Every clause describes what the system does: the credit
 * notice and freeze days come from the same preferences the credit check
 * reads, so the terms cannot promise what the software does not do.
 *
 * A page is a title, a lede, the date and version, and sections; a section
 * holds blocks of kind `p` (a paragraph), `ul` (bullets), `dl` (term and
 * text pairs) or `note` (a boxed paragraph). Text is plain and escaped by the
 * view; emphasis is the view's, never markup inside the text.
 */
final class Legal
{
    public function __construct(private readonly CompanyIdentity $company, private readonly CreditCheck $credit) {}

    /** @return array{title: string, lede: string, effective: string, version: string, sections: list<array{id: string, heading: string, blocks: list<array<string, mixed>>}>} */
    public function privacy(): array
    {
        $name = $this->company->letterhead()['name'];
        $address = $this->company->letterhead()['address'];
        $phone = (string) Copy::value('contact.phone');
        $email = (string) (Copy::value('legal.privacy.email') ?: Copy::value('contact.email'));
        $nib = (string) Copy::value('legal.nib');
        $correction = (int) Copy::value('legal.privacy.correction_hours');
        $breach = (int) Copy::value('legal.privacy.breach_notice_hours');
        $server = (string) Copy::value('legal.privacy.server_location');

        return [
            'title' => 'Kebijakan Privasi',
            'lede' => 'Bagaimana kami mengumpulkan, memakai, menyimpan, dan melindungi data pribadi dalam sistem pemesanan grosir kami.',
            'effective' => $this->date((string) Copy::value('legal.privacy.effective_since')),
            'version' => (string) Copy::value('legal.privacy.version'),
            'sections' => [
                ['id' => 'dasar', 'heading' => '', 'blocks' => [
                    ['kind' => 'note', 'text' => 'Kebijakan ini disusun mengikuti Undang-Undang Nomor 27 Tahun 2022 tentang Pelindungan Data Pribadi (UU PDP) dan Peraturan Pemerintah Nomor 71 Tahun 2019 tentang Penyelenggaraan Sistem dan Transaksi Elektronik.'],
                ]],
                ['id' => 'pengendali', 'heading' => '1. Siapa yang bertanggung jawab', 'blocks' => [
                    ['kind' => 'p', 'text' => "Pengendali data pribadi dalam kebijakan ini adalah {$name}".($address !== '' ? ", beralamat di {$address}" : '').($nib !== '' ? ", dengan NIB {$nib}" : '').'.'],
                    ['kind' => 'p', 'text' => "Pertanyaan, permintaan akses, koreksi, atau penghapusan data pribadi dapat dikirim ke {$email} atau melalui nomor {$phone}."],
                ]],
                ['id' => 'siapa', 'heading' => '2. Untuk siapa kebijakan ini berlaku', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Layanan kami ditujukan untuk pelaku usaha: bengkel, toko sparepart, dan distributor, bukan pembeli eceran. Data yang kami olah sebagian besar adalah data perusahaan, yang bukan merupakan data pribadi menurut UU PDP.'],
                    ['kind' => 'p', 'text' => 'Sebagian di antaranya tetap merupakan data pribadi, dan kebijakan ini berlaku penuh atas bagian tersebut: nama narahubung di perusahaan pelanggan, akun masuk pengguna portal, akun staf kami, dan, bagi pelanggan yang berbentuk usaha perseorangan, NPWP serta alamat pajak yang melekat pada orang.'],
                ]],
                ['id' => 'jenis', 'heading' => '3. Data apa yang kami olah', 'blocks' => [
                    ['kind' => 'dl', 'items' => [
                        ['Data pelanggan', 'Nama usaha, alamat, nomor telepon, email, NPWP dan alamat pajak, limit kredit dan termin pembayaran. Tujuan: pelaksanaan perjanjian jual beli dan kewajiban perpajakan. Disimpan selama hubungan usaha berjalan dan sepuluh tahun sesudahnya untuk dokumen perusahaan.'],
                        ['Narahubung pelanggan', 'Nama, jabatan, nomor telepon, dan email orang yang mewakili pelanggan. Tujuan: komunikasi pesanan, pengiriman, dan penagihan. Disimpan selama orang itu menjadi narahubung.'],
                        ['Akun portal', 'Nama, email, nomor telepon, kata sandi dalam bentuk hash, bahasa pilihan, dan waktu masuk terakhir. Tujuan: akses ke portal pelanggan. Disimpan selama akun aktif.'],
                        ['Catatan transaksi', 'Pesanan, surat jalan, faktur, pembayaran, dan jejak audit atas setiap perubahannya. Tujuan: pembukuan, perpajakan, dan pembuktian. Disimpan sepuluh tahun sesuai ketentuan perpajakan.'],
                        ['Akun staf', 'Nama, email, nomor identitas dan rekening yang disimpan terenkripsi, hak akses, dan jejak tindakan. Tujuan: pengelolaan sistem dan kepegawaian. Disimpan selama masa kerja dan sesuai ketentuan ketenagakerjaan.'],
                    ]],
                    ['kind' => 'p', 'text' => 'Kami tidak mengolah data pribadi yang bersifat spesifik sebagaimana dimaksud Pasal 4 ayat (2) UU PDP: data kesehatan, biometrik, genetika, catatan kejahatan, atau data anak. Kami juga tidak meminta nomor kartu kredit maupun data rekening pribadi Anda; pembayaran dilakukan melalui transfer ke rekening perusahaan kami, tunai, atau giro.'],
                ]],
                ['id' => 'sumber', 'heading' => '4. Dari mana data itu kami peroleh', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Sebagian besar berasal langsung dari Anda: saat mengajukan pembukaan akun grosir, saat memesan, dan saat berkomunikasi dengan tim kami. Sebagian lagi terbentuk dengan sendirinya saat Anda memakai sistem: catatan pesanan, jejak audit, dan waktu masuk terakhir. Data pembayaran dicatat oleh tim keuangan kami ketika transfer, tunai, atau giro Anda dikonfirmasi.'],
                    ['kind' => 'p', 'text' => 'Kami tidak membeli data dari pihak ketiga dan tidak mengumpulkan data Anda dari sumber lain di luar itu.'],
                ]],
                ['id' => 'pihak-ketiga', 'heading' => '5. Kepada siapa data dibagikan', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Kami tidak menjual data pribadi. Data dibagikan hanya kepada pihak berikut:'],
                    ['kind' => 'dl', 'items' => [
                        ['Direktorat Jenderal Pajak', 'Identitas wajib pajak dan nilai transaksi, sebatas yang wajib dilaporkan dalam rangka penerbitan faktur pajak dan pelaporan Pajak Pertambahan Nilai.'],
                        ['Jasa pengiriman', 'Nama penerima, alamat kirim, dan nomor telepon, sebatas yang diperlukan agar barang sampai.'],
                        ['Aparat penegak hukum atau instansi berwenang', 'Hanya atas permintaan yang sah menurut peraturan perundang-undangan.'],
                    ]],
                ]],
                ['id' => 'transfer', 'heading' => '6. Data tidak dikirim ke luar negeri', 'blocks' => [
                    ['kind' => 'p', 'text' => "Sistem dan basis data kami dijalankan pada server yang berlokasi di {$server}. Kami tidak memindahkan data pribadi ke luar wilayah Republik Indonesia, sehingga ketentuan Pasal 56 UU PDP mengenai transfer data pribadi ke luar negeri tidak berlaku dalam layanan ini."],
                ]],
                ['id' => 'cookie', 'heading' => '7. Cookie dan pelacakan', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Kami memasang tiga cookie, dan ketiganya bersifat teknis. Tidak ada cookie pelacakan, cookie iklan, maupun cookie profil di situs ini.'],
                    ['kind' => 'dl', 'items' => [
                        [(string) config('session.cookie'), 'Menjaga sesi Anda. Isinya berupa penanda sesi terenkripsi, bukan identitas Anda. Berlaku '.(int) config('session.lifetime').' menit dan tidak dapat dibaca oleh skrip di peramban.'],
                        ['XSRF-TOKEN', 'Melindungi formulir dari pemalsuan permintaan lintas situs, agar tidak ada situs lain yang bisa mengirim perintah atas nama Anda.'],
                        [Http\SiteLocale::COOKIE, 'Mengingat bahasa yang Anda pilih untuk situs publik (Indonesia atau Inggris). Hanya dipasang bila Anda menekan tombol ganti bahasa, berlaku satu tahun, dan isinya hanya kode bahasa itu.'],
                    ]],
                    ['kind' => 'p', 'text' => 'Dua yang pertama diperlukan agar situs berfungsi, karena itu tidak tersedia pilihan untuk menolaknya selain dengan tidak memakai situs ini. Yang ketiga hanya ada bila Anda memintanya. Tidak satu pun dipakai untuk mengenali Anda antar kunjungan maupun antar situs.'],
                    ['kind' => 'p', 'text' => 'Kami tidak memasang layanan analitik, piksel iklan, tombol media sosial, maupun pelacak pihak ketiga. Seluruh berkas halaman ini, termasuk huruf dan gambar, dilayani dari server kami sendiri. Kunjungan Anda ke halaman publik kami karena itu tidak diketahui pihak mana pun selain kami.'],
                ]],
                ['id' => 'keamanan', 'heading' => '8. Bagaimana data diamankan', 'blocks' => [
                    ['kind' => 'ul', 'items' => [
                        'Seluruh lalu lintas ke sistem dienkripsi dengan TLS.',
                        'Kata sandi disimpan dalam bentuk hash satu arah. Kami tidak dapat membacanya dan tidak akan pernah meminta Anda menyebutkannya.',
                        'Staf dan pelanggan masuk melalui dua sistem otentikasi yang terpisah, sehingga akun pelanggan tidak memiliki identitas apa pun di panel staf.',
                        'Setiap pelanggan hanya dapat melihat data perusahaannya sendiri. Pembatasan ini melekat pada kueri basis data, bukan pada tampilan.',
                        'Akses staf dibatasi menurut peran. Staf gudang, misalnya, tidak dapat melihat harga maupun data kredit pelanggan.',
                        'Setiap tindakan yang berdampak pada uang dicatat dalam jejak audit yang hanya bisa ditambah, tidak bisa diubah atau dihapus.',
                        'Nomor identitas dan rekening disimpan terenkripsi. Basis data dicadangkan secara berkala dalam bentuk terenkripsi.',
                    ]],
                ]],
                ['id' => 'insiden', 'heading' => '9. Jika terjadi kebocoran data', 'blocks' => [
                    ['kind' => 'p', 'text' => "Apabila terjadi kegagalan pelindungan data pribadi, kami akan menyampaikan pemberitahuan tertulis kepada Anda dan kepada lembaga yang berwenang paling lambat {$breach} jam sejak diketahui, sebagaimana diwajibkan Pasal 46 UU PDP. Pemberitahuan itu akan memuat data pribadi yang terungkap, kapan dan bagaimana hal itu terjadi, serta penanganan dan pemulihan yang kami lakukan."],
                ]],
                ['id' => 'hak', 'heading' => '10. Hak Anda atas data pribadi Anda', 'blocks' => [
                    ['kind' => 'p', 'text' => 'UU PDP memberi Anda hak-hak berikut, dan kami menghormatinya:'],
                    ['kind' => 'dl', 'items' => [
                        ['Mendapat informasi', 'Mengetahui identitas kami, dasar hukum, tujuan, dan akuntabilitas pihak yang meminta data Anda.'],
                        ['Melihat dan mendapat salinan', 'Meminta akses dan salinan data pribadi Anda yang kami simpan.'],
                        ['Memperbaiki', 'Melengkapi, memperbarui, atau membetulkan data yang keliru.'],
                        ['Menghapus', 'Meminta pengakhiran pemrosesan, penghapusan, atau pemusnahan data pribadi Anda.'],
                        ['Menarik persetujuan', 'Menarik persetujuan atas pemrosesan yang memang didasarkan pada persetujuan Anda.'],
                        ['Menolak keputusan otomatis', 'Menolak tindakan pengambilan keputusan yang semata-mata otomatis dan berdampak hukum bagi Anda.'],
                        ['Menunda atau membatasi', 'Meminta penundaan atau pembatasan pemrosesan data pribadi Anda.'],
                        ['Memindahkan data', 'Memperoleh dan mengirimkan data pribadi Anda kepada pengendali lain dalam format yang dapat dibaca sistem.'],
                        ['Menggugat dan menuntut ganti rugi', 'Mengajukan gugatan dan menerima ganti rugi atas pelanggaran data pribadi Anda.'],
                    ]],
                    ['kind' => 'p', 'text' => "Kirim permintaan ke {$email} dari alamat email yang terdaftar pada akun Anda, atau hubungi kami melalui nomor di atas. Permintaan pembetulan data akan kami tindak lanjuti paling lambat {$correction} jam sejak diterima, sesuai Pasal 37 UU PDP. Kami tidak memungut biaya atas permintaan yang wajar."],
                    ['kind' => 'p', 'text' => 'Hak penghapusan tidak berlaku mutlak. Faktur, catatan pembayaran, dan pembukuan yang menyertainya wajib kami simpan selama sepuluh tahun menurut ketentuan perpajakan dan ketentuan mengenai dokumen perusahaan. Selama kewajiban itu berjalan, data tersebut tidak dapat kami hapus atas permintaan, tetapi pemakaiannya kami batasi hanya untuk memenuhi kewajiban tersebut.'],
                ]],
                ['id' => 'anak', 'heading' => '11. Anak-anak', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Layanan ini ditujukan untuk pelaku usaha dan tidak dimaksudkan bagi anak. Kami tidak dengan sengaja mengumpulkan data pribadi anak. Jika Anda mengetahui hal itu terjadi, hubungi kami dan data tersebut akan kami hapus.'],
                ]],
                ['id' => 'perubahan', 'heading' => '12. Perubahan kebijakan ini', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Kebijakan ini dapat kami perbarui. Setiap versi mencantumkan tanggal berlakunya di bagian atas halaman. Bila perubahannya bersifat mendasar, kami akan memberitahukannya kepada pelanggan terdaftar melalui email sebelum perubahan itu berlaku.'],
                ]],
                ['id' => 'pengaduan', 'heading' => '13. Pengaduan', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Sampaikan keberatan Anda lebih dahulu kepada kami; sebagian besar persoalan selesai di situ. Jika Anda menilai penanganan kami belum memadai, Anda berhak mengadu kepada lembaga yang berwenang di bidang pelindungan data pribadi, dan berhak mengajukan gugatan sebagaimana diatur Pasal 12 UU PDP.'],
                ]],
            ],
        ];
    }

    /** @return array{title: string, lede: string, effective: string, version: string, sections: list<array{id: string, heading: string, blocks: list<array<string, mixed>>}>} */
    public function terms(): array
    {
        $name = $this->company->letterhead()['name'];
        $address = $this->company->letterhead()['address'];
        $phone = (string) Copy::value('contact.phone');
        $email = (string) Copy::value('contact.email');
        $hours = (string) Copy::pick(Copy::value('contact.hours'));
        $lateFee = (string) Copy::value('legal.terms.late_fee_percent_per_month');
        $claimDays = (int) Copy::value('legal.terms.claim_days');
        $forum = (string) Copy::value('legal.terms.dispute_forum');
        $notice = $this->credit->noticeDays();
        $freeze = $this->credit->freezeDays();

        $aging = [];
        if ($notice > 0) {
            $aging[] = "Atas faktur yang belum dilunasi {$notice} hari sejak pesanan disetujui (tanggal penerimaan pesanan), kami mengirim pemberitahuan tertulis kepada pembeli dan tim penjualan yang menangani.";
        }
        if ($freeze > 0) {
            $aging[] = "Bila faktur tertua yang belum dilunasi melewati {$freeze} hari sejak pesanan disetujui, pesanan baru tidak dikonfirmasi sampai faktur itu dilunasi. Pembatasan ini dicabut seketika saat pelunasan diterima.";
        }

        return [
            'title' => 'Syarat Penjualan',
            'lede' => "Ketentuan yang berlaku atas setiap penjualan grosir dari {$name} kepada pelanggan terdaftar.",
            'effective' => $this->date((string) Copy::value('legal.terms.effective_since')),
            'version' => (string) Copy::value('legal.terms.version'),
            'sections' => [
                ['id' => 'dasar', 'heading' => '', 'blocks' => [
                    ['kind' => 'note', 'text' => 'Syarat ini berlaku antara pelaku usaha. Pembeli kami adalah bengkel, toko sparepart, dan distributor yang membeli untuk dijual kembali atau untuk keperluan usahanya, bukan konsumen akhir. Dengan mengajukan pesanan, pembeli menyatakan bertindak dalam rangka usahanya dan menyetujui syarat ini.'],
                ]],
                ['id' => 'definisi', 'heading' => '1. Pengertian', 'blocks' => [
                    ['kind' => 'dl', 'items' => [
                        ['Penjual', $name.'.'],
                        ['Pembeli', 'Badan usaha yang akunnya telah kami setujui.'],
                        ['Pesanan', 'Permintaan pembelian yang diajukan melalui portal pelanggan, melalui tim penjualan kami, atau tertulis.'],
                        ['Faktur', 'Tagihan yang kami terbitkan atas pesanan yang telah dikonfirmasi dan dikirim.'],
                        ['Surat jalan', 'Dokumen pengantar barang yang ditandatangani penerima.'],
                    ]],
                ]],
                ['id' => 'akun', 'heading' => '2. Pembukaan akun', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Tidak ada pendaftaran mandiri. Akun grosir terbentuk setelah kami memverifikasi legalitas usaha pembeli dan menyepakati limit kredit serta termin pembayaran. Setiap pembeli ditangani oleh cabang dan tim penjualan yang kami tetapkan. Kami berhak menolak pengajuan akun tanpa menyebutkan alasan.'],
                    ['kind' => 'p', 'text' => 'Pembeli wajib menjaga kerahasiaan akun masuk portalnya dan bertanggung jawab atas seluruh pesanan yang diajukan melalui akun tersebut. Beri tahu kami segera jika akun diduga dipakai pihak yang tidak berhak.'],
                ]],
                ['id' => 'harga', 'heading' => '3. Harga', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Harga bersifat khusus per pelanggan dan tidak ditampilkan kepada umum. Harga yang berlaku adalah harga yang tercantum pada saat pesanan dikonfirmasi, bukan pada saat pesanan diajukan.'],
                    ['kind' => 'p', 'text' => 'Pada saat konfirmasi, harga satuan, diskon, dasar pengenaan pajak, dan PPN untuk setiap baris pesanan disalin dan disimpan pada pesanan tersebut. Perubahan daftar harga sesudahnya tidak mengubah pesanan yang sudah dikonfirmasi maupun faktur yang sudah terbit.'],
                    ['kind' => 'p', 'text' => 'Seluruh harga dinyatakan dalam Rupiah dan belum termasuk PPN, kecuali dinyatakan lain. PPN dihitung per baris sesuai ketentuan yang berlaku dan ditambahkan pada faktur.'],
                ]],
                ['id' => 'pesanan', 'heading' => '4. Pesanan dan konfirmasi', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Pesanan yang diajukan pembeli merupakan penawaran, bukan perjanjian yang mengikat. Perjanjian jual beli baru terbentuk ketika kami mengonfirmasi pesanan. Kami dapat menolak atau menyesuaikan pesanan, antara lain karena stok tidak mencukupi, limit kredit terlampaui, atau tagihan sebelumnya belum diselesaikan.'],
                    ['kind' => 'p', 'text' => 'Saat dikonfirmasi, stok disisihkan untuk pesanan tersebut di gudang yang akan mengirimnya. Bila barang berada di beberapa gudang, pesanan dapat dikirim dalam beberapa bagian dari beberapa cabang, masing-masing dengan surat jalan dan fakturnya sendiri.'],
                    ['kind' => 'p', 'text' => 'Pembatalan oleh pembeli atas pesanan yang telah dikonfirmasi hanya dapat dilakukan dengan persetujuan tertulis kami, dan tidak dapat dilakukan setelah barang dikirim.'],
                ]],
                ['id' => 'kredit', 'heading' => '5. Limit kredit dan termin pembayaran', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Setiap pembeli memiliki limit kredit dan termin pembayaran yang disepakati saat akun disetujui. Pesanan yang membuat total tagihan berjalan melampaui limit kredit tidak akan dikonfirmasi sampai sebagian tagihan diselesaikan, kecuali kami menyetujui pelampauan tersebut secara tertulis.'],
                    ['kind' => 'p', 'text' => 'Kami dapat meninjau, menurunkan, atau menangguhkan limit kredit sewaktu-waktu, terutama bila terdapat tunggakan. Peninjauan itu tidak menghapus kewajiban atas tagihan yang sudah berjalan.'],
                ]],
                ['id' => 'pembayaran', 'heading' => '6. Pembayaran', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Pembayaran dilakukan melalui transfer ke rekening perusahaan kami yang tercantum pada setiap faktur, atau secara tunai maupun bilyet giro melalui perwakilan penjualan kami.'],
                    ['kind' => 'p', 'text' => 'Pembayaran dianggap diterima pada saat dana masuk dan dikonfirmasi oleh tim keuangan kami, bukan pada saat bukti transfer dikirimkan. Bilyet giro dianggap lunas pada saat dananya cair, bukan pada saat bilyet diserahkan.'],
                    ['kind' => 'p', 'text' => 'Pembayaran wajib dilakukan penuh sesuai nilai faktur, tanpa pemotongan, pengurangan, atau perjumpaan utang, kecuali disepakati tertulis. Bila pembeli memiliki lebih dari satu faktur terbuka, pembayaran diperhitungkan terhadap faktur yang paling lama jatuh tempo, kecuali pembeli menyatakan lain.'],
                    ['kind' => 'p', 'text' => "Atas tagihan yang lewat jatuh tempo, kami berhak mengenakan denda keterlambatan sebesar {$lateFee}% per bulan dari jumlah tertunggak, dihitung harian sejak tanggal jatuh tempo sampai pembayaran diterima."],
                    ...array_map(fn (string $text) => ['kind' => 'p', 'text' => $text], $aging),
                    ['kind' => 'p', 'text' => 'Selama terdapat tunggakan, kami juga berhak:'],
                    ['kind' => 'ul', 'items' => [
                        'menahan pengiriman atas pesanan yang sedang berjalan;',
                        'menolak konfirmasi pesanan baru;',
                        'menangguhkan atau menurunkan limit kredit; dan',
                        'menagih seluruh tagihan yang belum jatuh tempo untuk dibayar seketika.',
                    ]],
                    ['kind' => 'p', 'text' => 'Biaya penagihan yang wajar, termasuk biaya jasa hukum, menjadi beban pembeli apabila penagihan harus ditempuh melalui pihak ketiga atau jalur hukum.'],
                ]],
                ['id' => 'pajak', 'heading' => '7. Faktur dan faktur pajak', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Faktur diterbitkan atas pesanan yang telah dikonfirmasi dan dikirimkan kepada pembeli. Faktur pajak diterbitkan sesuai ketentuan perpajakan yang berlaku, berdasarkan NPWP, nama wajib pajak, dan alamat pajak yang terdaftar pada akun pembeli.'],
                    ['kind' => 'p', 'text' => 'Pembeli wajib memastikan data perpajakannya benar dan memberitahukan perubahannya sebelum pesanan dikonfirmasi. Koreksi faktur pajak akibat data yang keliru dari pembeli menjadi tanggung jawab pembeli.'],
                ]],
                ['id' => 'pengiriman', 'heading' => '8. Pengiriman dan penyerahan risiko', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Barang dikirim ke alamat kirim yang terdaftar, disertai surat jalan. Perkiraan waktu kirim yang kami sampaikan adalah perkiraan, bukan jaminan, dan keterlambatan pengiriman tidak dengan sendirinya membatalkan pesanan.'],
                    ['kind' => 'p', 'text' => 'Risiko atas barang beralih kepada pembeli pada saat barang diserahkan kepada pembeli atau kepada pengangkut yang ditunjuk pembeli. Pembeli atau wakilnya wajib memeriksa dan menandatangani surat jalan pada saat penerimaan.'],
                    ['kind' => 'p', 'text' => 'Hak milik atas barang tetap berada pada kami sampai seluruh tagihan atas barang tersebut dibayar lunas. Sebelum itu, pembeli menyimpan barang tersebut sebagai barang milik kami, meskipun pembeli tetap boleh menjualnya kembali dalam kegiatan usaha sehari-hari.'],
                ]],
                ['id' => 'klaim', 'heading' => '9. Klaim, pengembalian, dan garansi', 'blocks' => [
                    ['kind' => 'p', 'text' => "Klaim atas jumlah yang kurang, barang yang tidak sesuai pesanan, atau kerusakan yang terlihat wajib disampaikan paling lambat {$claimDays} hari kerja setelah barang diterima, disertai nomor surat jalan dan foto. Lewat batas itu barang dianggap diterima dalam keadaan baik dan sesuai."],
                    ['kind' => 'p', 'text' => 'Barang hanya dapat dikembalikan bila kami menyetujuinya lebih dahulu secara tertulis, dan hanya bila barang:'],
                    ['kind' => 'ul', 'items' => [
                        'masih dalam kemasan asli dan belum dipasang atau dipakai;',
                        'disertai salinan surat jalan atau faktur; dan',
                        'bukan barang pesanan khusus atau barang yang dipesan atas permintaan tertentu pembeli.',
                    ]],
                    ['kind' => 'p', 'text' => 'Pengembalian diajukan melalui perwakilan penjualan kami dan diperiksa oleh tim gudang kami sebelum diterima. Barang yang dikembalikan tanpa persetujuan tertulis kami tidak akan diproses. Kami dapat mengenakan biaya penanganan atas pengembalian yang bukan disebabkan kesalahan kami.'],
                    ['kind' => 'p', 'text' => 'Untuk barang yang mengandung cacat produksi, berlaku ketentuan garansi dari prinsipal atau pabrikan merk yang bersangkutan. Kami membantu meneruskan klaim garansi tersebut, namun lingkup dan jangka waktunya ditentukan oleh prinsipal, bukan oleh kami. Garansi tidak berlaku atas kerusakan akibat pemasangan yang keliru, pemakaian di luar peruntukan, kecelakaan, modifikasi, atau keausan wajar.'],
                ]],
                ['id' => 'tanggung-jawab', 'heading' => '10. Batasan tanggung jawab', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Tanggung jawab kami atas suatu pesanan dibatasi paling banyak sebesar nilai barang yang bersangkutan sebagaimana tercantum dalam faktur.'],
                    ['kind' => 'p', 'text' => 'Kami tidak bertanggung jawab atas kerugian tidak langsung, antara lain kehilangan keuntungan, kehilangan pelanggan, waktu henti kendaraan, atau tuntutan dari pihak ketiga kepada pembeli, kecuali dalam hal kesengajaan atau kelalaian berat di pihak kami, atau sepanjang pembatasan itu tidak diperkenankan oleh peraturan perundang-undangan.'],
                ]],
                ['id' => 'keadaan-kahar', 'heading' => '11. Keadaan kahar', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Kami tidak dianggap lalai atas keterlambatan atau kegagalan pelaksanaan yang disebabkan keadaan di luar kendali wajar kami, antara lain bencana alam, kebakaran, wabah, kerusuhan, pemogokan, gangguan pasokan atau pengangkutan, gangguan jaringan listrik atau telekomunikasi, serta perubahan peraturan. Kewajiban pembayaran atas barang yang sudah diterima tidak ditangguhkan oleh keadaan tersebut.'],
                ]],
                ['id' => 'data', 'heading' => '12. Data pribadi', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Pengolahan data pribadi dalam rangka syarat ini tunduk pada Kebijakan Privasi kami, yang tautannya ada di bagian bawah halaman ini.'],
                ]],
                ['id' => 'perubahan', 'heading' => '13. Perubahan syarat', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Kami dapat mengubah syarat ini. Versi yang berlaku atas suatu pesanan adalah versi yang tercantum pada halaman ini pada saat pesanan dikonfirmasi. Perubahan yang bersifat mendasar akan kami beritahukan kepada pelanggan terdaftar sebelum berlaku.'],
                ]],
                ['id' => 'hukum', 'heading' => '14. Hukum yang berlaku dan penyelesaian sengketa', 'blocks' => [
                    ['kind' => 'p', 'text' => 'Syarat ini tunduk pada hukum Republik Indonesia. Pemesanan, konfirmasi, dan dokumen yang diterbitkan melalui sistem ini dilakukan secara elektronik, dan para pihak sepakat bahwa catatan elektronik tersebut merupakan alat bukti yang sah sesuai ketentuan mengenai informasi dan transaksi elektronik.'],
                    ['kind' => 'p', 'text' => "Setiap perselisihan diupayakan diselesaikan secara musyawarah lebih dahulu. Apabila tidak tercapai kesepakatan dalam 30 hari, para pihak sepakat menyelesaikannya melalui {$forum}."],
                ]],
                ['id' => 'kontak', 'heading' => '15. Hubungi kami', 'blocks' => [
                    ['kind' => 'ul', 'items' => array_values(array_filter([$name, $address, $phone, $email, $hours], fn (string $v) => $v !== ''))],
                ]],
            ],
        ];
    }

    private function date(string $iso): string
    {
        return Carbon::parse($iso !== '' ? $iso : 'today')->locale('id')->translatedFormat('j F Y');
    }
}
