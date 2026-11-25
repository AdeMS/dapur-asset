<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

/**
 * Unggah bertahap: browser memotong file menjadi potongan kecil (bawaan 1 MB) dan mengirimnya satu per satu, sehingga
 * file yang lebih besar dari batas unggah PHP (upload_max_filesize) tetap bisa diunggah tanpa mengubah pengaturan
 * server.
 *
 * Alur: mulai() -> potongan() berulang, berurutan -> selesai() memberi file utuh di folder sementara -> pemanggil
 * memindahkannya (Penyimpanan) -> buang(). Unggahan yang tidak selesai dalam sehari dibersihkan otomatis.
 */
final class Unggahan
{
    /** Ukuran satu potongan dari browser. Harus di bawah upload_max_filesize dan post_max_size server. */
    public const POTONGAN = 1024 * 1024;

    private const UMUR_MAKS = 86400;

    public function __construct(
        private readonly string $folder,
        private readonly int $ukuranMaks,
    ) {
    }

    /**
     * @param array<string, string|int|bool> $data keterangan tujuan dari pemanggil (aset, versi, folder, bongkar)
     * @return string kode unggahan (32 heksadesimal)
     */
    public function mulai(string $namaFile, int $ukuran, int $userId, array $data = []): string
    {
        if ($ukuran < 0 || $ukuran > $this->ukuranMaks) {
            throw new GagalAset(sprintf('File terlalu besar (maksimal %s).', Penyimpanan::ukuranTeks($this->ukuranMaks)));
        }

        $this->bersihkan();
        $kode   = bin2hex(random_bytes(16));
        $folder = $this->folder . '/' . $kode;
        $lama   = umask(0007);
        try {
            if (! @mkdir($folder, 0770, true)) {
                throw new GagalAset('Folder sementara unggahan tidak bisa dibuat.');
            }
        } finally {
            umask($lama);
        }

        $meta = ['nama' => $namaFile, 'ukuran' => $ukuran, 'user' => $userId, 'diterima' => 0, 'potongan' => 0, 'data' => $data];
        file_put_contents($folder . '/meta.json', json_encode($meta));
        touch($folder . '/isi');

        return $kode;
    }

    /** Menambahkan potongan ke-$nomor (mulai 0). Potongan harus dikirim berurutan. */
    public function potongan(string $kode, int $userId, int $nomor, string $isi): int
    {
        $meta = $this->meta($kode, $userId);
        if ($nomor !== $meta['potongan']) {
            throw new GagalAset('Urutan potongan unggahan tidak sesuai. Ulangi unggahan.');
        }

        if (strlen($isi) > self::POTONGAN || $meta['diterima'] + strlen($isi) > $meta['ukuran']) {
            throw new GagalAset('Potongan unggahan lebih besar dari yang diumumkan.');
        }

        $folder = $this->folder . '/' . $kode;
        if (file_put_contents($folder . '/isi', $isi, FILE_APPEND | LOCK_EX) !== strlen($isi)) {
            throw new GagalAset('Potongan unggahan tidak bisa disimpan.');
        }

        $meta['diterima'] += strlen($isi);
        $meta['potongan']++;
        file_put_contents($folder . '/meta.json', json_encode($meta));

        return $meta['diterima'];
    }

    /**
     * @return array{file: string, nama: string, data: array<string, mixed>} file utuh di folder sementara
     */
    public function selesai(string $kode, int $userId): array
    {
        $meta = $this->meta($kode, $userId);
        $file = $this->folder . '/' . $kode . '/isi';
        clearstatcache(true, $file);
        if ($meta['diterima'] !== $meta['ukuran'] || filesize($file) !== $meta['ukuran']) {
            throw new GagalAset('Unggahan belum lengkap. Ulangi unggahan.');
        }

        return ['file' => $file, 'nama' => (string) $meta['nama'], 'data' => is_array($meta['data']) ? $meta['data'] : []];
    }

    public function buang(string $kode): void
    {
        if (self::kodeSah($kode) && is_dir($this->folder . '/' . $kode)) {
            Penyimpanan::hapusFisik($this->folder . '/' . $kode);
        }
    }

    public static function kodeSah(string $kode): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $kode) === 1;
    }

    /** @return array{nama: string, ukuran: int, user: int, diterima: int, potongan: int, data: mixed} */
    private function meta(string $kode, int $userId): array
    {
        $file = $this->folder . '/' . $kode . '/meta.json';
        $meta = self::kodeSah($kode) && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($meta) || ($meta['user'] ?? null) !== $userId) {
            throw new GagalAset('Unggahan tidak ditemukan atau sudah kedaluwarsa. Ulangi unggahan.');
        }

        return $meta;
    }

    /** Membuang unggahan yang tidak selesai lebih dari sehari. */
    private function bersihkan(): void
    {
        foreach (is_dir($this->folder) ? (scandir($this->folder) ?: []) : [] as $kode) {
            $folder = $this->folder . '/' . $kode;
            if (self::kodeSah($kode) && is_dir($folder) && (@filemtime($folder . '/meta.json') ?: 0) < time() - self::UMUR_MAKS) {
                try {
                    Penyimpanan::hapusFisik($folder);
                } catch (GagalAset) {
                    // dicoba lagi pada unggahan berikutnya
                }
            }
        }
    }
}
