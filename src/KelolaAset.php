<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use InvalidArgumentException;

/**
 * Aturan pengelolaan aset, dipakai dashboard dan perintah terminal. Setiap perubahan dicatat di riwayat aset.
 *
 * - Aset dari Composer dan file konfigurasi terkunci; aset Composer hanya bisa diberi versi tambahan dan versi bawaan.
 * - Isi versi yang sudah terbit tidak bisa diubah (browser menyimpannya lama); perubahan = versi baru.
 * - Aset tanpa versi bisa diubah kapan saja; browser hanya menyimpannya sebentar.
 */
final class KelolaAset
{
    public function __construct(
        private readonly Katalog $katalog,
        private readonly DaftarAset $daftar,
        private readonly Pengaturan $pengaturan,
        private readonly ?int $pelakuId = null,
        private readonly ?string $pelaku = null,
    ) {
    }

    public function aset(string $nama): Aset
    {
        $aset = $this->katalog->cari($nama);
        if ($aset === null) {
            throw new GagalAset("Aset {$nama} tidak ditemukan.");
        }

        return $aset;
    }

    public function tambah(string $nama, bool $berversi, string $akses, string $keterangan = ''): Aset
    {
        $nama = $this->nama($nama);
        if ($this->katalog->cari($nama) !== null) {
            throw new GagalAset("Aset {$nama} sudah ada.");
        }

        $this->daftar->tambah($nama, $berversi, self::akses($akses), self::keterangan($keterangan));
        (new Penyimpanan($this->katalog->folderAset($nama)))->siapkan();
        $this->catat($nama, DaftarAset::TAMBAH, ($berversi ? 'berversi' : 'tanpa versi') . ', akses ' . self::akses($akses));

        return $this->segarkan($nama);
    }

    public function ubah(string $nama, string $akses, bool $aktif, string $keterangan): Aset
    {
        $aset = $this->aset($nama);
        if ($aset->terkunci() || $aset->id === null) {
            throw new GagalAset("Aset {$nama} terkunci (dari " . self::sumberTeks($aset) . ').');
        }

        $this->katalog->segarkan();
        $this->daftar->ubah($aset->id, self::akses($akses), $aktif, $aset->versiBawaan, self::keterangan($keterangan));
        $this->catat($nama, DaftarAset::UBAH, sprintf('akses %s, %s', self::akses($akses), $aktif ? 'aktif' : 'nonaktif'));

        return $this->segarkan($nama);
    }

    /** Menghapus aset dari dashboard beserta semua filenya. */
    public function hapus(string $nama): void
    {
        $aset = $this->aset($nama);
        if ($aset->sumber !== Aset::SUMBER_DATABASE || $aset->id === null) {
            throw new GagalAset("Aset {$nama} terkunci (dari " . self::sumberTeks($aset) . ').');
        }

        $this->daftar->hapus($aset->id);
        (new Penyimpanan($aset->folder))->hapusSemua();
        $this->catat($nama, DaftarAset::HAPUS);
    }

    /** Versi baru (belum terbit), kosong atau salinan dari versi lain. */
    public function tambahVersi(string $nama, string $versi, ?string $salinDari = null): Aset
    {
        $aset  = $this->asetUntukVersi($nama);
        $versi = self::versi($versi);
        if (isset($aset->versi[$versi])) {
            throw new GagalAset("Versi {$versi} sudah ada.");
        }

        $tujuan = new Penyimpanan($aset->folder . '/' . $versi);
        if ($salinDari !== null && $salinDari !== '') {
            $asal = $aset->versi[$salinDari] ?? throw new GagalAset("Versi {$salinDari} tidak ditemukan.");
            self::salinFolder($asal->folder, $aset->folder . '/' . $versi);
        } else {
            $tujuan->siapkan();
        }

        $this->daftar->tambahVersi($this->idAset($aset), $versi);
        $this->catat($nama, DaftarAset::TAMBAH_VERSI, $versi . ($salinDari ? " (salinan {$salinDari})" : ''));

        return $this->segarkan($nama);
    }

    public function terbitkan(string $nama, string $versi): Aset
    {
        $aset = $this->aset($nama);
        $v    = $aset->versi[$versi] ?? throw new GagalAset("Versi {$versi} tidak ditemukan.");
        if (! $v->dapatDiubah()) {
            throw new GagalAset("Versi {$versi} sudah terbit.");
        }

        if ((new Penyimpanan($v->folder))->ringkasan()['jumlah'] === 0) {
            throw new GagalAset("Versi {$versi} masih kosong. Unggah file lebih dulu.");
        }

        $id = $this->idAset($aset);
        $this->daftar->tambahVersiBilaBelum($id, $versi);
        $this->daftar->terbitkan($id, $versi);
        $this->catat($nama, DaftarAset::TERBITKAN, $versi);

        return $this->segarkan($nama);
    }

    /** Menghapus versi yang belum terbit beserta filenya. */
    public function hapusVersi(string $nama, string $versi): Aset
    {
        $aset = $this->aset($nama);
        $v    = $aset->versi[$versi] ?? throw new GagalAset("Versi {$versi} tidak ditemukan.");
        if (! $v->dapatDiubah()) {
            throw new GagalAset("Versi {$versi} sudah terbit, jadi tidak bisa dihapus: mungkin sedang dipakai website atau tersimpan di browser pengunjung.");
        }

        (new Penyimpanan($v->folder))->hapusSemua();
        if ($aset->id !== null) {
            $this->daftar->hapusVersi($aset->id, $versi);
        }
        $this->catat($nama, DaftarAset::HAPUS_VERSI, $versi);

        return $this->segarkan($nama);
    }

    /** Versi bawaan aset; null = versi terbit yang terbaru (aset Composer: versi paket yang terpasang). */
    public function aturVersiBawaan(string $nama, ?string $versi): Aset
    {
        $aset = $this->aset($nama);
        if (! $aset->berversi || $aset->sumber === Aset::SUMBER_FILE) {
            throw new GagalAset("Versi bawaan aset {$nama} tidak bisa diatur dari sini.");
        }

        if ($versi !== null && ! $aset->adaVersiTerbit($versi)) {
            throw new GagalAset("Versi {$versi} belum terbit.");
        }

        $this->daftar->aturVersiBawaan($this->idAset($aset), $versi);
        $this->catat($nama, DaftarAset::UBAH, 'versi bawaan ' . ($versi ?? 'terbaru'));

        return $this->segarkan($nama);
    }

    /** Versi pilihan satu website; null = ikut versi bawaan aset. */
    public function pilihVersi(string $nama, string $lingkup, ?string $versi): Aset
    {
        $aset = $this->aset($nama);
        if (! $aset->berversi || $aset->sumber === Aset::SUMBER_FILE) {
            throw new GagalAset("Versi aset {$nama} tidak bisa dipilih per website.");
        }

        if ($versi !== null && ! $aset->adaVersiTerbit($versi)) {
            throw new GagalAset("Versi {$versi} belum terbit.");
        }

        $this->daftar->aturPilihan($lingkup, $this->idAset($aset), $versi);
        $this->catat($nama, DaftarAset::PILIH_VERSI, $lingkup . ': ' . ($versi ?? 'ikut versi bawaan'));

        return $this->segarkan($nama);
    }

    /** Pengelola file untuk isi aset (aset tanpa versi) atau satu versi yang belum terbit. */
    public function penyimpanan(string $nama, ?string $versi, bool $untukMengubah = true): Penyimpanan
    {
        $aset = $this->aset($nama);
        if ($aset->berversi) {
            $v = $versi === null ? null : ($aset->versi[$versi] ?? null);
            if ($v === null) {
                throw new GagalAset('Versi tidak ditemukan.');
            }
            if ($untukMengubah && ! $v->dapatDiubah()) {
                throw new GagalAset("Versi {$versi} sudah terbit atau terkunci, jadi isinya tidak bisa diubah. Buat versi baru.");
            }

            return new Penyimpanan($v->folder, $this->pengaturan->unggahMaks);
        }

        if ($untukMengubah && ! $aset->isiDapatDiubah()) {
            throw new GagalAset("Aset {$nama} terkunci (dari " . self::sumberTeks($aset) . ').');
        }

        return new Penyimpanan($aset->folder, $this->pengaturan->unggahMaks);
    }

    /** Menyimpan file unggahan; bila $bongkar dan file ZIP, isinya dibongkar ke folder tujuan. */
    public function simpanUnggahan(string $nama, ?string $versi, string $folder, string $namaFile, string $fileSementara, bool $bongkar): string
    {
        $simpan = $this->penyimpanan($nama, $versi);
        $simpan->siapkan();
        $label  = $versi === null ? $nama : "{$nama} {$versi}";
        if ($bongkar && JenisFile::akhiran($namaFile) === 'zip') {
            $jumlah = $simpan->bongkarZip($fileSementara, $folder);
            $this->catat($nama, DaftarAset::UNGGAH, sprintf('%s: ZIP %s dibongkar ke /%s (%d file)', $label, $namaFile, $folder, $jumlah));

            return "{$namaFile} dibongkar: {$jumlah} file.";
        }

        $jalur = ltrim($folder . '/' . $namaFile, '/');
        $ganti = $simpan->simpanFile($fileSementara, $jalur);
        $this->catat($nama, DaftarAset::UNGGAH, "{$label}: /{$jalur}" . ($ganti ? ' (mengganti file lama)' : ''));

        return $ganti ? "{$jalur} diganti." : "{$jalur} diunggah.";
    }

    public function buatFolder(string $nama, ?string $versi, string $jalur): string
    {
        $simpan = $this->penyimpanan($nama, $versi);
        $simpan->siapkan();
        $hasil = $simpan->buatFolder($jalur);
        $this->catat($nama, DaftarAset::BUAT_FOLDER, ($versi === null ? '' : "{$versi}: ") . '/' . $hasil);

        return $hasil;
    }

    public function gantiNama(string $nama, ?string $versi, string $jalur, string $namaBaru): string
    {
        $hasil = $this->penyimpanan($nama, $versi)->gantiNama($jalur, $namaBaru);
        $this->catat($nama, DaftarAset::GANTI_NAMA, ($versi === null ? '' : "{$versi}: ") . "/{$jalur} -> /{$hasil}");

        return $hasil;
    }

    public function hapusFile(string $nama, ?string $versi, string $jalur): void
    {
        $this->penyimpanan($nama, $versi)->hapus($jalur);
        $this->catat($nama, DaftarAset::HAPUS_FILE, ($versi === null ? '' : "{$versi}: ") . '/' . Nama::periksaJalur($jalur));
    }

    public static function sumberTeks(Aset $aset): string
    {
        return match ($aset->sumber) {
            Aset::SUMBER_COMPOSER => 'paket Composer ' . ($aset->paket ?? ''),
            Aset::SUMBER_FILE     => 'file konfigurasi',
            default               => 'dashboard',
        };
    }

    private function asetUntukVersi(string $nama): Aset
    {
        $aset = $this->aset($nama);
        if (! $aset->bisaTambahVersi()) {
            throw new GagalAset($aset->berversi ? "Aset {$nama} terkunci (dari file konfigurasi)." : "Aset {$nama} tidak memakai versi.");
        }

        return $aset;
    }

    /** Nomor aset di database; aset Composer dicatat di database saat pertama diberi versi tambahan atau pilihan. */
    private function idAset(Aset $aset): int
    {
        if ($aset->id !== null) {
            return $aset->id;
        }

        if ($aset->sumber !== Aset::SUMBER_COMPOSER) {
            throw new GagalAset("Aset {$aset->nama} tidak tercatat di database.");
        }

        return $this->daftar->tambah($aset->nama, true, Aset::PUBLIK, '');
    }

    private function segarkan(string $nama): Aset
    {
        $this->katalog->segarkan();

        return $this->katalog->cari($nama) ?? throw new GagalAset("Aset {$nama} tidak ditemukan.");
    }

    private function catat(string $aset, string $peristiwa, string $keterangan = ''): void
    {
        $this->daftar->catat($aset, $peristiwa, $keterangan, $this->pelakuId, $this->pelaku);
    }

    private function nama(string $nama): string
    {
        try {
            return Nama::periksaAset($nama);
        } catch (InvalidArgumentException $e) {
            throw new GagalAset($e->getMessage());
        }
    }

    private static function versi(string $versi): string
    {
        try {
            return Nama::periksaVersi($versi);
        } catch (InvalidArgumentException $e) {
            throw new GagalAset($e->getMessage());
        }
    }

    private static function akses(string $akses): string
    {
        return $akses === Aset::LOGIN ? Aset::LOGIN : Aset::PUBLIK;
    }

    private static function keterangan(string $keterangan): string
    {
        return mb_substr(trim($keterangan), 0, 255);
    }

    private static function salinFolder(string $asal, string $tujuan): void
    {
        (new Penyimpanan($tujuan))->siapkan();
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($asal, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iter as $f) {
            $relatif = substr($f->getPathname(), strlen($asal) + 1);
            $parts   = explode('/', $relatif);
            if (array_filter($parts, static fn (string $p): bool => ! Nama::sahBagianJalur($p)) !== [] || $f->isLink()) {
                continue;
            }

            $ke = $tujuan . '/' . $relatif;
            if ($f->isDir()) {
                if (! is_dir($ke)) {
                    @mkdir($ke);
                    @chmod($ke, Penyimpanan::IZIN_FOLDER);
                }
            } elseif (JenisFile::diizinkan($f->getFilename())) {
                if (! @copy($f->getPathname(), $ke)) {
                    throw new GagalAset('File tidak bisa disalin: ' . $relatif);
                }
                @chmod($ke, Penyimpanan::IZIN_FILE);
            }
        }
    }
}
