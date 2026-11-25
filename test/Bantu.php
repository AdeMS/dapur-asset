<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset\Test;

use AdeMS\Dapur\Asset\AsetComposer;
use AdeMS\Dapur\Asset\AsetSkema;
use AdeMS\Dapur\Asset\DaftarAset;
use AdeMS\Dapur\Asset\Katalog;
use AdeMS\Dapur\Asset\KelolaAset;
use AdeMS\Dapur\Asset\NamaTabel;
use AdeMS\Dapur\Asset\Pengaturan;
use AdeMS\Dapur\Database\KonteksSkema;
use Laminas\Db\Adapter\Adapter;

/** Lingkungan uji: folder sementara + SQLite di memori dengan tabel aset. */
final class Bantu
{
    public readonly string $folder;
    public readonly Adapter $db;
    public readonly DaftarAset $daftar;

    /** @var array<string, array{paket: string, folder: string, versi: string}> */
    public array $composer = [];

    /** @var array<string, mixed> */
    public array $config = [];

    public function __construct()
    {
        $this->folder = sys_get_temp_dir() . '/dapur-asset-uji-' . bin2hex(random_bytes(6));
        mkdir($this->folder . '/data/asset', 0777, true);

        $this->db = new Adapter(['driver' => 'Pdo_Sqlite', 'database' => ':memory:']);
        $k        = new KonteksSkema($this->db, 'uji_');
        $skema    = new AsetSkema();
        foreach ($skema->tabel($k) as $sql) {
            $k->jalankan($sql);
        }
        foreach ($skema->indeks($k) as $sql) {
            $k->jalankan($sql);
        }

        $this->daftar = new DaftarAset($this->db, new NamaTabel('uji_'));
    }

    public function pengaturan(): Pengaturan
    {
        return new Pengaturan($this->config, $this->folder);
    }

    public function katalog(string $lingkup = 'dashboard'): Katalog
    {
        return new Katalog($this->pengaturan(), new AsetComposer(null, $this->composer), $this->daftar, $lingkup);
    }

    public function kelola(): KelolaAset
    {
        return new KelolaAset($this->katalog(), $this->daftar, $this->pengaturan(), 1, 'admin@contoh.test');
    }

    public function file(string $jalur, string $isi = 'isi'): string
    {
        $penuh = $this->folder . '/' . $jalur;
        @mkdir(dirname($penuh), 0777, true);
        file_put_contents($penuh, $isi);

        return $penuh;
    }

    public function hapus(): void
    {
        exec('rm -rf ' . escapeshellarg($this->folder));
    }
}
