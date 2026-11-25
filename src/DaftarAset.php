<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use Laminas\Db\Adapter\Adapter;
use Throwable;

/** Data aset di database: aset, versi unggahan, pilihan versi tiap website, dan riwayat perubahan. */
final class DaftarAset
{
    /** Peristiwa riwayat aset. */
    public const TAMBAH         = 'tambah';
    public const UBAH           = 'ubah';
    public const HAPUS          = 'hapus';
    public const TAMBAH_VERSI   = 'tambah_versi';
    public const TERBITKAN      = 'terbitkan';
    public const HAPUS_VERSI    = 'hapus_versi';
    public const PILIH_VERSI    = 'pilih_versi';
    public const UNGGAH         = 'unggah';
    public const BUAT_FOLDER    = 'buat_folder';
    public const GANTI_NAMA     = 'ganti_nama';
    public const HAPUS_FILE     = 'hapus_file';

    public const LABEL = [
        self::TAMBAH       => 'Aset dibuat',
        self::UBAH         => 'Pengaturan diubah',
        self::HAPUS        => 'Aset dihapus',
        self::TAMBAH_VERSI => 'Versi dibuat',
        self::TERBITKAN    => 'Versi diterbitkan',
        self::HAPUS_VERSI  => 'Versi dihapus',
        self::PILIH_VERSI  => 'Versi website',
        self::UNGGAH       => 'Unggah',
        self::BUAT_FOLDER  => 'Folder dibuat',
        self::GANTI_NAMA   => 'Ganti nama',
        self::HAPUS_FILE   => 'File dihapus',
    ];

    private ?bool $tersedia = null;

    public function __construct(
        private readonly Adapter $db,
        private readonly NamaTabel $t,
    ) {
    }

    /** Tabel aset sudah dibuat (`php dapur pasang`). */
    public function tersedia(): bool
    {
        if ($this->tersedia !== null) {
            return $this->tersedia;
        }

        try {
            $this->baris('SELECT 1 FROM ' . $this->q($this->t->aset) . ' WHERE 1 = 0');

            return $this->tersedia = true;
        } catch (Throwable) {
            return $this->tersedia = false;
        }
    }

    /** @return array<string, array<string, mixed>> nama => baris tabel aset */
    public function semua(): array
    {
        $hasil = [];
        foreach ($this->baris('SELECT * FROM ' . $this->q($this->t->aset) . ' ORDER BY nama') as $b) {
            $hasil[(string) $b['nama']] = $b;
        }

        return $hasil;
    }

    /** @return array<int, array<string, ?string>> aset_id => [versi => terbit_pada] */
    public function semuaVersi(): array
    {
        $hasil = [];
        foreach ($this->baris('SELECT aset_id, versi, terbit_pada FROM ' . $this->q($this->t->versi)) as $b) {
            $hasil[(int) $b['aset_id']][(string) $b['versi']] = $b['terbit_pada'] === null ? null : (string) $b['terbit_pada'];
        }

        return $hasil;
    }

    /** @return array<string, string> nama aset => versi pilihan website ini */
    public function pilihanWebsite(string $lingkup): array
    {
        $hasil = [];
        $sql   = sprintf(
            'SELECT a.nama, w.versi FROM %s w JOIN %s a ON a.id = w.aset_id WHERE w.lingkup = ?',
            $this->q($this->t->website),
            $this->q($this->t->aset),
        );
        foreach ($this->baris($sql, [$lingkup]) as $b) {
            $hasil[(string) $b['nama']] = (string) $b['versi'];
        }

        return $hasil;
    }

    /** @return array<string, string> lingkup => versi pilihan, untuk satu aset */
    public function pilihanAset(int $asetId): array
    {
        $hasil = [];
        $sql   = 'SELECT lingkup, versi FROM ' . $this->q($this->t->website) . ' WHERE aset_id = ? ORDER BY lingkup';
        foreach ($this->baris($sql, [$asetId]) as $b) {
            $hasil[(string) $b['lingkup']] = (string) $b['versi'];
        }

        return $hasil;
    }

    public function tambah(string $nama, bool $berversi, string $akses, string $keterangan = ''): int
    {
        $this->jalankan(
            'INSERT INTO ' . $this->q($this->t->aset) . ' (nama, berversi, akses, aktif, keterangan, dibuat_pada) VALUES (?, ?, ?, 1, ?, ?)',
            [$nama, $berversi ? 1 : 0, $akses, $keterangan === '' ? null : $keterangan, self::sekarang()],
        );

        return (int) $this->db->getDriver()->getLastGeneratedValue();
    }

    public function ubah(int $id, string $akses, bool $aktif, ?string $versiBawaan, string $keterangan): void
    {
        $this->jalankan(
            'UPDATE ' . $this->q($this->t->aset) . ' SET akses = ?, aktif = ?, versi_bawaan = ?, keterangan = ?, diubah_pada = ? WHERE id = ?',
            [$akses, $aktif ? 1 : 0, $versiBawaan, $keterangan === '' ? null : $keterangan, self::sekarang(), $id],
        );
    }

    public function aturVersiBawaan(int $id, ?string $versi): void
    {
        $this->jalankan(
            'UPDATE ' . $this->q($this->t->aset) . ' SET versi_bawaan = ?, diubah_pada = ? WHERE id = ?',
            [$versi, self::sekarang(), $id],
        );
    }

    public function hapus(int $id): void
    {
        // Hapus anak lebih dulu: SQLite tanpa PRAGMA foreign_keys tidak menjalankan ON DELETE CASCADE.
        $this->jalankan('DELETE FROM ' . $this->q($this->t->website) . ' WHERE aset_id = ?', [$id]);
        $this->jalankan('DELETE FROM ' . $this->q($this->t->versi) . ' WHERE aset_id = ?', [$id]);
        $this->jalankan('DELETE FROM ' . $this->q($this->t->aset) . ' WHERE id = ?', [$id]);
    }

    public function tambahVersi(int $id, string $versi): void
    {
        $this->jalankan(
            'INSERT INTO ' . $this->q($this->t->versi) . ' (aset_id, versi, terbit_pada, dibuat_pada) VALUES (?, ?, NULL, ?)',
            [$id, $versi, self::sekarang()],
        );
    }

    /** Mencatat versi yang foldernya sudah ada tetapi belum tercatat (mis. disalin lewat terminal). */
    public function tambahVersiBilaBelum(int $id, string $versi): void
    {
        $ada = $this->baris('SELECT 1 FROM ' . $this->q($this->t->versi) . ' WHERE aset_id = ? AND versi = ?', [$id, $versi]);
        if ($ada === []) {
            $this->tambahVersi($id, $versi);
        }
    }

    public function terbitkan(int $id, string $versi): void
    {
        $this->jalankan(
            'UPDATE ' . $this->q($this->t->versi) . ' SET terbit_pada = ? WHERE aset_id = ? AND versi = ? AND terbit_pada IS NULL',
            [self::sekarang(), $id, $versi],
        );
    }

    public function hapusVersi(int $id, string $versi): void
    {
        $this->jalankan('DELETE FROM ' . $this->q($this->t->website) . ' WHERE aset_id = ? AND versi = ?', [$id, $versi]);
        $this->jalankan('DELETE FROM ' . $this->q($this->t->versi) . ' WHERE aset_id = ? AND versi = ?', [$id, $versi]);
        $this->jalankan(
            'UPDATE ' . $this->q($this->t->aset) . ' SET versi_bawaan = NULL WHERE id = ? AND versi_bawaan = ?',
            [$id, $versi],
        );
    }

    /** Versi pilihan website; null = kembali ke versi bawaan aset. */
    public function aturPilihan(string $lingkup, int $id, ?string $versi): void
    {
        $this->jalankan('DELETE FROM ' . $this->q($this->t->website) . ' WHERE lingkup = ? AND aset_id = ?', [$lingkup, $id]);
        if ($versi !== null) {
            $this->jalankan(
                'INSERT INTO ' . $this->q($this->t->website) . ' (lingkup, aset_id, versi, diubah_pada) VALUES (?, ?, ?, ?)',
                [$lingkup, $id, $versi, self::sekarang()],
            );
        }
    }

    public function catat(string $aset, string $peristiwa, string $keterangan = '', ?int $pelakuId = null, ?string $pelaku = null): void
    {
        $this->jalankan(
            'INSERT INTO ' . $this->q($this->t->riwayat) . ' (aset, pelaku_id, pelaku, peristiwa, keterangan, waktu) VALUES (?, ?, ?, ?, ?, ?)',
            [$aset, $pelakuId, $pelaku === null ? null : mb_substr($pelaku, 0, 255), $peristiwa, $keterangan === '' ? null : mb_substr($keterangan, 0, 255), self::sekarang()],
        );
    }

    /** @return list<array<string, mixed>> riwayat terbaru; aset null = semua aset */
    public function riwayat(?string $aset = null, int $jumlah = 20): array
    {
        $jumlah = max(1, min(1000, $jumlah));
        $sql    = 'SELECT * FROM ' . $this->q($this->t->riwayat)
            . ($aset === null ? '' : ' WHERE aset = ?')
            . ' ORDER BY waktu DESC, id DESC LIMIT ' . $jumlah;

        return $this->baris($sql, $aset === null ? [] : [$aset]);
    }

    public static function sekarang(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function q(string $nama): string
    {
        return $this->db->getPlatform()->quoteIdentifier($nama);
    }

    /** @param list<mixed> $parameter */
    private function jalankan(string $sql, array $parameter = []): void
    {
        $this->db->query($sql, $parameter);
    }

    /**
     * @param list<mixed> $parameter
     * @return list<array<string, mixed>>
     */
    private function baris(string $sql, array $parameter = []): array
    {
        $hasil = [];
        foreach ($this->db->query($sql, $parameter) as $b) {
            $hasil[] = is_array($b) ? $b : (array) $b->getArrayCopy();
        }

        return $hasil;
    }
}
