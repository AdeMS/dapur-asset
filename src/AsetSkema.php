<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use AdeMS\Dapur\Database\KonteksSkema;
use AdeMS\Dapur\Database\SkemaModul;

/**
 * Tabel modul aset untuk `php dapur pasang` (adems/dapur-database):
 *
 * - asset: aset yang dikelola dari dashboard (nama, akses, versi bawaan, aktif);
 * - asset_versi: versi aset yang diunggah, beserta waktu diterbitkan (versi terbit tidak bisa diubah lagi);
 * - asset_website: versi pilihan tiap website (lingkup = 'dashboard' atau nama domain website);
 * - asset_riwayat: catatan perubahan aset (siapa, apa, kapan).
 */
final class AsetSkema implements SkemaModul
{
    public const NAMA = 'asset';

    public function nama(): string
    {
        return self::NAMA;
    }

    public function paket(): string
    {
        return 'adems/dapur-asset';
    }

    public function butuh(): array
    {
        return [];
    }

    public function tabel(KonteksSkema $k): array
    {
        $t      = new NamaTabel($k->awalan);
        $mysql  = $k->platform === KonteksSkema::MYSQL;
        $id     = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $idRef  = $mysql ? 'INT UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
        $akhir  = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $q      = static fn (string $n): string => $k->kutip($n);

        return [
            $t->aset => "CREATE TABLE {$q($t->aset)} (
                id {$id},
                nama VARCHAR(101) NOT NULL,
                berversi SMALLINT NOT NULL DEFAULT 1,
                akses VARCHAR(10) NOT NULL DEFAULT 'publik',
                aktif SMALLINT NOT NULL DEFAULT 1,
                versi_bawaan VARCHAR(40) NULL,
                keterangan VARCHAR(255) NULL,
                dibuat_pada DATETIME NOT NULL,
                diubah_pada DATETIME NULL,
                CONSTRAINT {$q($t->aset . '_nama_unik')} UNIQUE (nama)
            ){$akhir}",
            $t->versi => "CREATE TABLE {$q($t->versi)} (
                aset_id {$idRef},
                versi VARCHAR(40) NOT NULL,
                terbit_pada DATETIME NULL,
                dibuat_pada DATETIME NOT NULL,
                PRIMARY KEY (aset_id, versi),
                CONSTRAINT {$q($t->versi . '_aset_fk')} FOREIGN KEY (aset_id) REFERENCES {$q($t->aset)} (id) ON DELETE CASCADE
            ){$akhir}",
            $t->website => "CREATE TABLE {$q($t->website)} (
                lingkup VARCHAR(190) NOT NULL,
                aset_id {$idRef},
                versi VARCHAR(40) NOT NULL,
                diubah_pada DATETIME NOT NULL,
                PRIMARY KEY (lingkup, aset_id),
                CONSTRAINT {$q($t->website . '_aset_fk')} FOREIGN KEY (aset_id) REFERENCES {$q($t->aset)} (id) ON DELETE CASCADE
            ){$akhir}",
            $t->riwayat => "CREATE TABLE {$q($t->riwayat)} (
                id {$id},
                aset VARCHAR(101) NOT NULL,
                pelaku_id {$idRef} NULL,
                pelaku VARCHAR(255) NULL,
                peristiwa VARCHAR(30) NOT NULL,
                keterangan VARCHAR(255) NULL,
                waktu DATETIME NOT NULL
            ){$akhir}",
        ];
    }

    public function kolom(KonteksSkema $k): array
    {
        return [];
    }

    public function indeks(KonteksSkema $k): array
    {
        $t = new NamaTabel($k->awalan);

        return [
            $t->riwayat . '_aset_idx' => sprintf(
                'CREATE INDEX %s ON %s (aset, waktu)',
                $k->kutip($t->riwayat . '_aset_idx'),
                $k->kutip($t->riwayat),
            ),
        ];
    }

    public function langkah(KonteksSkema $k): array
    {
        return [];
    }
}
