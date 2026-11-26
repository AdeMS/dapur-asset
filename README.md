# Aset Dapur AdeMS

Modul aset untuk [Dapur AdeMS](https://github.com/AdeMS/dapur). Modul ini melayani dan mengelola file CSS,
JavaScript, gambar, font, dan dokumen, dikelompokkan per **vendor/aplikasi** dan per **versi**:

```text
/asset/<vendor>/<aplikasi>/[<versi>/]<file>
/asset/twbs/bootstrap/5.3.8/css/bootstrap.min.css
/asset/adems/uang/1.0.0/css/uang.css
/asset/adems/uang/css/uang.css        <- tanpa versi: versi yang dipakai website ini
```

Yang disediakan modul ini:

- route `/asset/...` yang melayani file aset dengan aturan cache yang tepat;
- view helper `aset()` agar template tidak perlu menulis nomor versi;
- tabel aset untuk `php dapur pasang` (adems/dapur-database);
- perintah `vendor/bin/dapur-asset` untuk melihat aset dari terminal.

Halaman pengelolaannya (membuat aset, mengunggah file, menerbitkan versi, memilih versi per website) ada di
[Dashboard Dapur AdeMS](https://github.com/AdeMS/dapur-dashboard) 2.9 ke atas, menu **Pengaturan > Aset** (khusus admin
super).

## Konsep

### Sumber aset

| Sumber | Contoh | Bisa diubah dari dashboard? |
|---|---|---|
| Paket Composer | `twbs/bootstrap`, `twbs/bootstrap-icons`, modul yang menyatakan `extra.dapur.asset` | Tidak (terkunci), tetapi bisa diberi **versi tambahan** |
| File konfigurasi | `'asset' => ['aset' => [...]]` | Tidak (terkunci) |
| Dashboard | aset yang dibuat lewat halaman Aset | Ya |

Bila namanya sama, aset dari file konfigurasi menang, lalu Composer, lalu dashboard.

### Versi

Aset **berversi** (misalnya pustaka CSS/JS) menyimpan setiap versi di folder sendiri:
`data/asset/<vendor>/<aplikasi>/<versi>/`. Versi baru berstatus **draf**: filenya bisa diunggah dan diubah, tetapi
belum dilayani. Setelah **diterbitkan**, isi versi itu dikunci dan tidak bisa diubah lagi, karena browser pengunjung
boleh menyimpannya sampai setahun. Perubahan berikutnya dibuat sebagai versi baru (bisa disalin dari versi lama).

Versi yang dipakai saat alamat tidak menyebut versi:

1. pilihan website (file konfigurasi website, lalu pilihan di dashboard);
2. versi **bawaan** aset (aset Composer: versi paket yang terpasang);
3. versi terbit yang **terbaru**.

Aset **tanpa versi** (misalnya gambar atau dokumen) menyimpan filenya langsung di `data/asset/<vendor>/<aplikasi>/`
dan bisa diubah kapan saja; browser hanya menyimpannya 5 menit.

### Akses

- **Publik**: siapa pun bisa membuka filenya.
- **Wajib login**: hanya dilayani bila ada pemeriksa akses (layanan `PenjagaAkses`). Dashboard menyediakannya:
  pengunjung harus sedang login di website itu. Tanpa pemeriksa akses, aset wajib login tidak pernah dilayani (404).

### Keamanan

- Hanya jenis file tertentu yang disimpan dan dilayani (lihat `JenisFile`): css, js, gambar, font, pdf, audio, video,
  dan sejenisnya. File `.php`, `.phtml`, `.phar`, `.html`, dan file tersembunyi (`.htaccess`) selalu ditolak.
- Jalur file diperiksa: tidak bisa keluar dari folder aset (`..`, symlink), dan folder sistem NAS seperti `@eaDir`
  diabaikan.
- File SVG dilayani dengan `Content-Security-Policy: sandbox`, jadi skrip di dalamnya tidak dijalankan.
- File ZIP diperiksa seluruhnya sebelum dibongkar; bila ada satu file yang tidak diizinkan, tidak ada yang dibongkar.

## Pemasangan

1. Tambahkan paket ke `composer.local.json` di folder inti Dapur (atau pasang Dashboard 2.9, yang sudah membawanya):

   ```json
   { "require": { "adems/dapur-asset": "^1.0" } }
   ```

   lalu `composer update`.
2. Daftarkan modul di `config/modul.local.php` inti agar route `/asset/...` dan helper `aset()` berlaku untuk inti
   **dan semua website**:

   ```php
   return [
       AdeMS\Dapur\Asset\ConfigProvider::class,
       // ... modul lain, mis. AdeMS\Dapur\Dashboard\DashboardUtamaConfigProvider::class
   ];
   ```
3. Buat tabelnya: `php dapur pasang`.
4. Siapkan folder `data/asset/` di inti Dapur yang **bisa ditulis web server** (mis. grup web server, izin 2770),
   agar file bisa diunggah dari dashboard. Folder sementara unggahan berada di `data/cache/asset-unggah/`.

Ekstensi PHP `zip` diperlukan untuk membongkar file ZIP; tanpa itu file tetap bisa diunggah satu per satu.

## Memakai aset di template

```php
<link href="<?= $this->aset('adems/uang', 'css/uang.css') ?>" rel="stylesheet">
<!-- /asset/adems/uang/1.0.0/css/uang.css -->
```

Alamat yang dihasilkan selalu menyebut versinya, jadi saat website pindah versi, alamatnya ikut berubah dan browser
mengambil file baru. Argumen ketiga adalah alamat cadangan bila aset tidak dikenal atau belum punya versi terbit:

```php
<?= $this->aset('twbs/bootstrap', 'css/bootstrap.min.css', '/asset/bootstrap/css/bootstrap.min.css') ?>
```

Semua aset, termasuk aset Composer, dilayani lewat PHP agar aksesnya bisa diatur. File aset Composer dibaca langsung
dari folder paketnya di `vendor/`, tanpa disalin. Layout inti Dapur 2.11 memakai helper ini untuk Bootstrap; alamat
lama `/asset/bootstrap/...` tetap dilayani sampai Dapur 3.0.

## Aset dari modul (Composer)

Modul Dapur bisa membawa CSS/JS-nya sendiri. Nyatakan foldernya di `composer.json` modul:

```json
"extra": {
    "dapur": {
        "asset": { "adems/uang": "public" }
    }
}
```

Isi folder `public/` modul itu dilayani di `/asset/adems/uang/<versi paket>/...`.

## Pengaturan

Pengaturan dasar ditulis di file konfigurasi (mis. `config/autoload/asset.local.php`) dan tidak bisa diubah dari
dashboard. Contoh lengkap dengan penjelasan ada di `config/asset.local.php.dist`:

```bash
cp vendor/adems/dapur-asset/config/asset.local.php.dist config/autoload/asset.local.php
```

Isinya:

```php
return [
    'asset' => [
        // Folder aset dari dashboard. Bawaan: <inti>/data/asset
        'folder' => '/path/ke/data/asset',
        // Aset terkunci dari folder mana pun di server.
        'aset' => [
            'adems/logo' => ['folder' => '/path/ke/logo', 'akses' => 'publik', 'berversi' => false],
        ],
        // Versi pilihan website ini (di config/autoload website); menang atas pilihan di dashboard.
        'versi' => ['twbs/bootstrap' => '5.3.8'],
        // Batas ukuran satu unggahan (sesudah ZIP dibongkar), dalam MB. Bawaan 100.
        'unggah_maks_mb' => 100,
    ],
];
```

Unggahan dari dashboard dikirim **bertahap** per 1 MB, jadi file yang lebih besar dari `upload_max_filesize` PHP tetap
bisa diunggah tanpa mengubah pengaturan server.

## Perintah

```text
vendor/bin/dapur-asset daftar
vendor/bin/dapur-asset info <vendor/aplikasi>
vendor/bin/dapur-asset terbitkan <vendor/aplikasi> <versi>
vendor/bin/dapur-asset riwayat [<vendor/aplikasi>] [--jumlah=20]
```

Tambahkan `--website=<domain>` untuk website tertentu (paket multi-website).

## Tabel

| Tabel | Isi |
|---|---|
| `asset` | aset dari dashboard: nama, berversi, akses, aktif, versi bawaan, keterangan |
| `asset_versi` | versi unggahan dan waktu terbitnya |
| `asset_website` | versi pilihan tiap website |
| `asset_riwayat` | catatan perubahan (siapa, apa, kapan) |

Nama tabel mengikuti awalan instalasi (`'database' => ['awalan_tabel' => ...]`).

## Memperbarui dari 0.1

Versi 0.1 hanya melayani `data/asset/<vendor>/<file>` tanpa pengelolaan. Mulai 1.0 alamatnya
`/asset/<vendor>/<aplikasi>/...`, aset harus terdaftar (dashboard, file konfigurasi, atau Composer), dan namespace-nya
`AdeMS\Dapur\Asset`. Pindahkan file lama ke aset baru lewat dashboard.

## Uji

```bash
composer install
composer test
```

## Lisensi

BSD-3-Clause. Lihat [LICENSE.md](LICENSE.md).
