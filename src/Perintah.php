<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use Throwable;

/**
 * Perintah terminal modul aset:
 *
 *   vendor/bin/dapur-asset daftar
 *   vendor/bin/dapur-asset info <vendor/aplikasi>
 *   vendor/bin/dapur-asset terbitkan <vendor/aplikasi> <versi>
 *   vendor/bin/dapur-asset riwayat [<vendor/aplikasi>] [--jumlah=20]
 */
final class Perintah
{
    public function __construct(
        private readonly Katalog $katalog,
        private readonly ?KelolaAset $kelola,
        private readonly ?DaftarAset $daftar,
    ) {
    }

    /** @param list<string> $argumen */
    public function jalankan(array $argumen): int
    {
        $bebas  = array_values(array_filter($argumen, static fn (string $a): bool => ! str_starts_with($a, '--')));
        $jumlah = 20;
        foreach ($argumen as $a) {
            if (preg_match('/^--jumlah=(\d+)$/', $a, $m) === 1) {
                $jumlah = (int) $m[1];
            }
        }

        try {
            return match ($bebas[0] ?? '') {
                'daftar'    => $this->daftar(),
                'info'      => $this->info($bebas[1] ?? ''),
                'terbitkan' => $this->terbitkan($bebas[1] ?? '', $bebas[2] ?? ''),
                'riwayat'   => $this->riwayat($bebas[1] ?? null, $jumlah),
                default     => $this->bantuan(),
            };
        } catch (GagalAset $e) {
            fwrite(STDERR, 'Gagal: ' . $e->getMessage() . PHP_EOL);

            return 1;
        }
    }

    private function daftar(): int
    {
        $semua = $this->katalog->semua();
        if ($semua === []) {
            echo 'Belum ada aset.' . PHP_EOL;

            return 0;
        }

        printf('%-32s %-10s %-7s %-8s %s%s', 'Aset', 'Sumber', 'Akses', 'Status', 'Versi dipakai', PHP_EOL);
        foreach ($semua as $aset) {
            printf(
                '%-32s %-10s %-7s %-8s %s%s',
                $aset->nama,
                $aset->sumber,
                $aset->akses,
                $aset->aktif ? 'aktif' : 'nonaktif',
                $aset->berversi ? ($this->katalog->versiUntuk($aset) ?? '(belum ada versi terbit)') : '(tanpa versi)',
                PHP_EOL,
            );
        }

        if ($this->daftar === null || ! $this->daftar->tersedia()) {
            echo PHP_EOL . 'Tabel aset belum dibuat: aset dari dashboard belum bisa dipakai. Jalankan: php dapur pasang' . PHP_EOL;
        }

        return 0;
    }

    private function info(string $nama): int
    {
        $aset = $this->katalog->cari($nama) ?? throw new GagalAset("Aset {$nama} tidak ditemukan.");

        printf('Aset        : %s%s', $aset->nama, PHP_EOL);
        printf('Sumber      : %s%s', KelolaAset::sumberTeks($aset), PHP_EOL);
        printf('Akses       : %s%s', $aset->akses === Aset::LOGIN ? 'wajib login' : 'publik', PHP_EOL);
        printf('Status      : %s%s', $aset->aktif ? 'aktif' : 'nonaktif', PHP_EOL);
        printf('Folder      : %s%s', $aset->folder, PHP_EOL);
        if ($aset->keterangan !== '') {
            printf('Keterangan  : %s%s', $aset->keterangan, PHP_EOL);
        }

        if (! $aset->berversi) {
            echo 'Versi       : (tanpa versi)' . PHP_EOL;

            return 0;
        }

        printf('Versi bawaan: %s%s', $aset->versiBawaan ?? '(terbaru)', PHP_EOL);
        printf('Dipakai di sini: %s%s', $this->katalog->versiUntuk($aset) ?? '-', PHP_EOL);
        echo 'Versi:' . PHP_EOL;
        foreach (Nama::urutkanTerbaru(array_keys($aset->versi)) as $v) {
            $versi = $aset->versi[$v];
            printf('  %-20s %-10s %s%s', $v, $versi->sumber, $versi->terbit ? 'terbit' : 'belum terbit', PHP_EOL);
        }

        return 0;
    }

    private function terbitkan(string $nama, string $versi): int
    {
        if ($this->kelola === null) {
            throw new GagalAset('Tabel aset belum dibuat. Jalankan: php dapur pasang');
        }

        $this->kelola->terbitkan($nama, $versi);
        echo "Versi {$versi} aset {$nama} diterbitkan." . PHP_EOL;

        return 0;
    }

    private function riwayat(?string $nama, int $jumlah): int
    {
        if ($this->daftar === null || ! $this->daftar->tersedia()) {
            throw new GagalAset('Tabel aset belum dibuat. Jalankan: php dapur pasang');
        }

        $baris = $this->daftar->riwayat($nama, $jumlah);
        if ($baris === []) {
            echo 'Belum ada riwayat.' . PHP_EOL;

            return 0;
        }

        foreach ($baris as $b) {
            printf(
                '%s UTC  %-24s %-18s %s%s%s',
                $b['waktu'],
                $b['aset'],
                DaftarAset::LABEL[$b['peristiwa']] ?? $b['peristiwa'],
                $b['keterangan'] ?? '',
                $b['pelaku'] ? '  (oleh ' . $b['pelaku'] . ')' : '',
                PHP_EOL,
            );
        }

        return 0;
    }

    private function bantuan(): int
    {
        echo <<<TEKS
            Perintah modul aset Dapur AdeMS:

              vendor/bin/dapur-asset daftar
              vendor/bin/dapur-asset info <vendor/aplikasi>
              vendor/bin/dapur-asset terbitkan <vendor/aplikasi> <versi>
              vendor/bin/dapur-asset riwayat [<vendor/aplikasi>] [--jumlah=20]

            Tambahkan --website=<domain> untuk website tertentu (paket multi-website).

            TEKS;

        return 1;
    }

    /** Teks pelaku untuk perubahan lewat terminal, mis. "terminal: budi". */
    public static function pelakuTerminal(): string
    {
        try {
            $akun = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
        } catch (Throwable) {
            $akun = '';
        }

        $akun = $akun !== '' ? $akun : (string) (getenv('USER') ?: '?');
        $sudo = getenv('SUDO_USER');

        return 'terminal: ' . $akun . (is_string($sudo) && $sudo !== '' && $sudo !== $akun ? ", lewat sudo dari {$sudo}" : '');
    }
}
