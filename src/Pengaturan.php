<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

/**
 * Pengaturan dasar aset dari file konfigurasi, `'asset' => [...]`. Bagian ini tidak bisa diubah dari dashboard.
 *
 *     'asset' => [
 *         // Folder penyimpanan aset yang dikelola dari dashboard. Bawaan: <inti>/data/asset.
 *         'folder' => '/path/ke/data/asset',
 *         // Aset terkunci: hanya diatur di file ini, tampil di dashboard tanpa bisa diubah.
 *         'aset' => [
 *             'adems/logo' => ['folder' => '/path/ke/logo', 'akses' => 'publik', 'berversi' => false],
 *         ],
 *         // Versi pilihan website ini; menang atas pilihan dari dashboard.
 *         'versi' => ['twbs/bootstrap' => '5.3.8'],
 *         // Batas ukuran satu unggahan (setelah ZIP dibongkar), dalam MB.
 *         'unggah_maks_mb' => 100,
 *     ],
 *
 * Nilai bawaan diletakkan di kelas ini, bukan di ConfigProvider, agar modul yang dimuat sesudah konfigurasi inti tidak
 * menimpa pengaturan inti.
 */
final class Pengaturan
{
    public const UNGGAH_MAKS_MB = 100;

    /** Jumlah file paling banyak di dalam satu ZIP. */
    public const ZIP_FILE_MAKS = 5000;

    public readonly string $folder;
    public readonly string $folderSementara;
    public readonly int $unggahMaks;

    /** @var array<string, array{folder: string, akses: string, berversi: bool, versi_bawaan: ?string, keterangan: string}> */
    public readonly array $asetTerkunci;

    /** @var array<string, string> nama aset => versi */
    public readonly array $versiWebsite;

    /** @param array<string, mixed> $config isi $config['asset'] */
    public function __construct(array $config = [], ?string $folderInti = null)
    {
        $inti = $folderInti ?? Lingkup::folderInti();

        $this->folder          = rtrim(is_string($config['folder'] ?? null) && $config['folder'] !== '' ? $config['folder'] : $inti . '/data/asset', '/');
        $this->folderSementara = rtrim(is_string($config['folder_sementara'] ?? null) && $config['folder_sementara'] !== '' ? $config['folder_sementara'] : $inti . '/data/cache/asset-unggah', '/');
        $this->unggahMaks      = max(1, (int) ($config['unggah_maks_mb'] ?? self::UNGGAH_MAKS_MB)) * 1024 * 1024;

        $terkunci = [];
        foreach (is_array($config['aset'] ?? null) ? $config['aset'] : [] as $nama => $isi) {
            if (! is_string($nama) || ! Nama::sahAset($nama) || ! is_array($isi) || ! is_string($isi['folder'] ?? null)) {
                continue;
            }

            $terkunci[$nama] = [
                'folder'       => rtrim($isi['folder'], '/'),
                'akses'        => ($isi['akses'] ?? Aset::PUBLIK) === Aset::LOGIN ? Aset::LOGIN : Aset::PUBLIK,
                'berversi'     => ($isi['berversi'] ?? false) === true,
                'versi_bawaan' => is_string($isi['versi_bawaan'] ?? null) && Nama::sahVersi($isi['versi_bawaan']) ? $isi['versi_bawaan'] : null,
                'keterangan'   => is_string($isi['keterangan'] ?? null) ? $isi['keterangan'] : '',
            ];
        }
        $this->asetTerkunci = $terkunci;

        $versi = [];
        foreach (is_array($config['versi'] ?? null) ? $config['versi'] : [] as $nama => $v) {
            if (is_string($nama) && is_string($v) && Nama::sahAset($nama) && Nama::sahVersi(Nama::normalkanVersi($v))) {
                $versi[$nama] = Nama::normalkanVersi($v);
            }
        }
        $this->versiWebsite = $versi;
    }
}
