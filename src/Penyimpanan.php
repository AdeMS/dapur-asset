<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Pengelola file di dalam satu folder isi aset (folder aset tanpa versi, atau folder satu versi): daftar, buat folder,
 * simpan file, bongkar ZIP, ganti nama, dan hapus.
 *
 * Semua jalur diperiksa: tidak bisa keluar dari folder isi (.., symlink), file tersembunyi dan folder sistem NAS
 * (@eaDir) ditolak, dan hanya jenis file di JenisFile yang boleh disimpan.
 */
final class Penyimpanan
{
    public const IZIN_FOLDER = 02770;
    public const IZIN_FILE   = 0660;

    public function __construct(
        private readonly string $akar,
        private readonly int $ukuranMaks = Pengaturan::UNGGAH_MAKS_MB * 1024 * 1024,
        private readonly int $zipFileMaks = Pengaturan::ZIP_FILE_MAKS,
    ) {
    }

    /** Membuat folder isi bila belum ada. */
    public function siapkan(): void
    {
        $this->buatFolderFisik($this->akar);
    }

    /**
     * Isi satu folder: folder dulu, lalu file, masing-masing urut nama.
     *
     * @return list<array{nama: string, jalur: string, folder: bool, ukuran: int, diubah: int}>
     */
    public function daftar(string $folder = ''): array
    {
        $dasar = $this->jalurAda($folder, true);
        $hasil = [];
        foreach (new FilesystemIterator($dasar) as $info) {
            $nama = $info->getFilename();
            if (! Nama::sahBagianJalur($nama) || $info->isLink()) {
                continue;
            }

            $hasil[] = [
                'nama'   => $nama,
                'jalur'  => ltrim(Nama::periksaJalur($folder) . '/' . $nama, '/'),
                'folder' => $info->isDir(),
                'ukuran' => $info->isDir() ? 0 : (int) $info->getSize(),
                'diubah' => (int) $info->getMTime(),
            ];
        }

        usort($hasil, static fn (array $a, array $b): int => [$b['folder'], strnatcasecmp($a['nama'], $b['nama'])] <=> [$a['folder'], 0]);

        return $hasil;
    }

    /** @return array{jumlah: int, ukuran: int} jumlah file dan total ukurannya */
    public function ringkasan(): array
    {
        if (! is_dir($this->akar)) {
            return ['jumlah' => 0, 'ukuran' => 0];
        }

        $jumlah = 0;
        $ukuran = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->akar, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $jumlah++;
                $ukuran += (int) $f->getSize();
            }
        }

        return ['jumlah' => $jumlah, 'ukuran' => $ukuran];
    }

    public function buatFolder(string $jalur): string
    {
        $bagian = $this->bagian($jalur);
        if ($bagian === []) {
            throw new GagalAset('Nama folder kosong.');
        }

        $this->buatFolderFisik($this->akar . '/' . implode('/', $bagian));

        return implode('/', $bagian);
    }

    /**
     * Menyimpan file dari lokasi sementara (unggahan) ke jalur di dalam folder isi. File yang sudah ada diganti.
     *
     * @return bool true bila menggantikan file lama
     */
    public function simpanFile(string $sumber, string $jalur): bool
    {
        $bagian = $this->bagian($jalur);
        if ($bagian === []) {
            throw new GagalAset('Nama file kosong.');
        }

        $nama = end($bagian);
        if (! JenisFile::diizinkan($nama)) {
            throw new GagalAset("Jenis file tidak diizinkan: {$nama}. Yang diizinkan: " . JenisFile::teks() . '.');
        }

        if (! is_file($sumber)) {
            throw new GagalAset('File unggahan tidak ditemukan.');
        }

        if (filesize($sumber) > $this->ukuranMaks) {
            throw new GagalAset(sprintf('File terlalu besar (maksimal %s).', self::ukuranTeks($this->ukuranMaks)));
        }

        $tujuan = $this->akar . '/' . implode('/', $bagian);
        $this->buatFolderFisik(dirname($tujuan));
        $this->pastikanDiDalam(dirname($tujuan));
        if (is_dir($tujuan) || is_link($tujuan)) {
            throw new GagalAset("Sudah ada folder bernama {$nama}.");
        }

        $ada = is_file($tujuan);
        $sementara = $tujuan . '.unggah-' . bin2hex(random_bytes(4));
        if (! @copy($sumber, $sementara) || ! @rename($sementara, $tujuan)) {
            @unlink($sementara);
            throw new GagalAset("File {$nama} tidak bisa disimpan. Pastikan folder aset bisa ditulis web server.");
        }
        @chmod($tujuan, self::IZIN_FILE);

        return $ada;
    }

    /**
     * Membongkar ZIP ke folder tujuan. Seluruh isi diperiksa lebih dulu; bila ada satu file yang tidak diizinkan,
     * tidak ada yang dibongkar. Bila semua isi berada di dalam satu folder teratas (mis. bootstrap-5.3.8-dist/),
     * folder itu dilewati.
     *
     * @return int jumlah file yang dibongkar
     */
    public function bongkarZip(string $fileZip, string $folder = ''): int
    {
        if (! class_exists(ZipArchive::class)) {
            throw new GagalAset('Ekstensi PHP zip belum terpasang di server, jadi file ZIP belum bisa dibongkar.');
        }

        $dasar = $this->bagian($folder);
        $zip   = new ZipArchive();
        if ($zip->open($fileZip, ZipArchive::RDONLY) !== true) {
            throw new GagalAset('File ZIP tidak bisa dibuka.');
        }

        try {
            $isi = $this->periksaZip($zip);
            foreach ($isi as $indeks => $bagian) {
                $tujuan = $this->akar . '/' . implode('/', [...$dasar, ...$bagian]);
                $this->buatFolderFisik(dirname($tujuan));
                $this->pastikanDiDalam(dirname($tujuan));
                if (is_dir($tujuan)) {
                    throw new GagalAset('Sudah ada folder bernama ' . implode('/', $bagian) . '.');
                }

                $baca = $zip->getStream((string) $zip->getNameIndex($indeks));
                $tulis = @fopen($tujuan, 'wb');
                if ($baca === false || $tulis === false) {
                    throw new GagalAset('Isi ZIP tidak bisa disimpan: ' . implode('/', $bagian) . '. Pastikan folder aset bisa ditulis web server.');
                }

                stream_copy_to_stream($baca, $tulis);
                fclose($baca);
                fclose($tulis);
                @chmod($tujuan, self::IZIN_FILE);
            }

            return count($isi);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, list<string>> indeks file di ZIP => bagian jalur tujuan
     */
    private function periksaZip(ZipArchive $zip): array
    {
        if ($zip->numFiles > $this->zipFileMaks) {
            throw new GagalAset(sprintf('ZIP berisi terlalu banyak file (maksimal %d).', $this->zipFileMaks));
        }

        $isi     = [];
        $total   = 0;
        $ditolak = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $nama = is_array($stat) ? (string) $stat['name'] : '';
            if ($nama === '' || str_ends_with($nama, '/')) {
                continue; // folder
            }

            // Berkas tambahan macOS di dalam ZIP diabaikan.
            if (str_starts_with($nama, '__MACOSX/') || basename($nama) === '.DS_Store') {
                continue;
            }

            try {
                $bagian = Nama::bagianJalur($nama);
            } catch (InvalidArgumentException) {
                $ditolak[] = $nama;
                continue;
            }

            if (! JenisFile::diizinkan($nama)) {
                $ditolak[] = $nama;
                continue;
            }

            $total += (int) $stat['size'];
            if ($total > $this->ukuranMaks) {
                throw new GagalAset(sprintf('Isi ZIP setelah dibongkar terlalu besar (maksimal %s).', self::ukuranTeks($this->ukuranMaks)));
            }

            $isi[$i] = $bagian;
        }

        if ($ditolak !== []) {
            throw new GagalAset(sprintf(
                'ZIP tidak dibongkar karena berisi %d file yang tidak diizinkan, misalnya: %s. Yang diizinkan: %s.',
                count($ditolak),
                implode(', ', array_slice($ditolak, 0, 3)),
                JenisFile::teks(),
            ));
        }

        if ($isi === []) {
            throw new GagalAset('ZIP tidak berisi file.');
        }

        return self::lewatiFolderTeratas($isi);
    }

    /**
     * @param array<int, list<string>> $isi
     * @return array<int, list<string>>
     */
    private static function lewatiFolderTeratas(array $isi): array
    {
        $teratas = null;
        foreach ($isi as $bagian) {
            if (count($bagian) < 2 || ($teratas !== null && $bagian[0] !== $teratas)) {
                return $isi;
            }
            $teratas = $bagian[0];
        }

        return array_map(static fn (array $b): array => array_slice($b, 1), $isi);
    }

    public function gantiNama(string $jalur, string $namaBaru): string
    {
        $lama = $this->jalurAda($jalur);
        if ($lama === $this->realAkar()) {
            throw new GagalAset('Folder isi aset tidak bisa diganti namanya.');
        }

        if (! Nama::sahBagianJalur($namaBaru) || str_contains($namaBaru, '/')) {
            throw new GagalAset('Nama baru tidak diizinkan.');
        }

        if (is_file($lama) && ! JenisFile::diizinkan($namaBaru)) {
            throw new GagalAset("Jenis file tidak diizinkan: {$namaBaru}.");
        }

        $baru = dirname($lama) . '/' . $namaBaru;
        if (file_exists($baru)) {
            throw new GagalAset("Sudah ada {$namaBaru} di folder ini.");
        }

        if (! @rename($lama, $baru)) {
            throw new GagalAset('Nama tidak bisa diganti.');
        }

        return ltrim(substr($baru, strlen($this->realAkar())), '/');
    }

    /** Menghapus file atau folder beserta isinya. */
    public function hapus(string $jalur): void
    {
        $target = $this->jalurAda($jalur);
        if ($target === $this->realAkar()) {
            throw new GagalAset('Folder isi aset tidak bisa dihapus dari sini.');
        }

        self::hapusFisik($target);
    }

    /** Menghapus seluruh folder isi (mis. versi yang belum terbit atau aset yang dihapus). */
    public function hapusSemua(): void
    {
        if (is_dir($this->akar)) {
            self::hapusFisik($this->realAkar());
        }
    }

    public static function hapusFisik(string $target): void
    {
        if (is_link($target) || is_file($target)) {
            if (! @unlink($target)) {
                throw new GagalAset('File tidak bisa dihapus: ' . basename($target));
            }

            return;
        }

        $isi = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($isi as $f) {
            $ok = $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            if (! $ok) {
                throw new GagalAset('Tidak bisa dihapus: ' . $f->getFilename());
            }
        }

        if (! @rmdir($target)) {
            throw new GagalAset('Folder tidak bisa dihapus: ' . basename($target));
        }
    }

    /** Jalur file untuk dilayani; null bila tidak ada, bukan file, atau jenisnya tidak diizinkan. */
    public function fileUntukDilayani(string $jalur): ?string
    {
        try {
            $bagian = Nama::bagianJalur($jalur);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($bagian === [] || ! JenisFile::diizinkan(end($bagian))) {
            return null;
        }

        $akar  = realpath($this->akar);
        $penuh = realpath($this->akar . '/' . implode('/', $bagian));
        if ($akar === false || $penuh === false || ! is_file($penuh) || ! str_starts_with($penuh, $akar . '/')) {
            return null;
        }

        return $penuh;
    }

    public static function ukuranTeks(int $byte): string
    {
        return match (true) {
            $byte >= 1024 * 1024 * 1024 => number_format($byte / 1024 / 1024 / 1024, 1, ',', '.') . ' GB',
            $byte >= 1024 * 1024        => number_format($byte / 1024 / 1024, 1, ',', '.') . ' MB',
            $byte >= 1024               => number_format($byte / 1024, 1, ',', '.') . ' KB',
            default                     => $byte . ' byte',
        };
    }

    /** @return list<string> */
    private function bagian(string $jalur): array
    {
        try {
            return Nama::bagianJalur($jalur);
        } catch (InvalidArgumentException $e) {
            throw new GagalAset($e->getMessage());
        }
    }

    private function realAkar(): string
    {
        $akar = realpath($this->akar);
        if ($akar === false) {
            throw new GagalAset('Folder aset belum ada.');
        }

        return $akar;
    }

    /** Jalur nyata dari file/folder yang sudah ada di dalam folder isi. */
    private function jalurAda(string $jalur, bool $folder = false): string
    {
        $akar  = $this->realAkar();
        $penuh = realpath($akar . '/' . implode('/', $this->bagian($jalur)));
        if ($penuh === false || ($penuh !== $akar && ! str_starts_with($penuh, $akar . '/'))) {
            throw new GagalAset('File atau folder tidak ditemukan.');
        }

        if ($folder && ! is_dir($penuh)) {
            throw new GagalAset('Folder tidak ditemukan.');
        }

        return $penuh;
    }

    private function pastikanDiDalam(string $folder): void
    {
        $akar  = $this->realAkar();
        $penuh = realpath($folder);
        if ($penuh === false || ($penuh !== $akar && ! str_starts_with($penuh, $akar . '/'))) {
            throw new GagalAset('Tujuan berada di luar folder aset.');
        }
    }

    /**
     * Membuat folder (beserta induknya) dengan izin 2770. Untuk folder di dalam folder isi, induk terdekat yang sudah
     * ada diperiksa lebih dulu agar folder tidak pernah dibuat lewat symlink yang mengarah ke luar.
     */
    private function buatFolderFisik(string $folder): void
    {
        if (is_dir($folder)) {
            return;
        }

        $dibuat = [];
        $cek    = $folder;
        while (! is_dir($cek) && $cek !== dirname($cek)) {
            $dibuat[] = $cek;
            $cek      = dirname($cek);
        }

        if (str_starts_with($folder, $this->akar . '/') && is_dir($this->akar)) {
            $this->pastikanDiDalam($cek);
        }

        foreach (array_reverse($dibuat) as $f) {
            if (! @mkdir($f) && ! is_dir($f)) {
                throw new GagalAset('Folder tidak bisa dibuat. Pastikan folder aset bisa ditulis web server.');
            }
            @chmod($f, self::IZIN_FOLDER);
        }
    }
}
