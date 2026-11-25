<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use Closure;

/**
 * View helper `aset()`: alamat file aset dengan versi yang dipakai website ini.
 *
 *     <link href="<?= $this->aset('twbs/bootstrap', 'css/bootstrap.min.css') ?>" rel="stylesheet">
 *     -> /asset/twbs/bootstrap/5.3.8/css/bootstrap.min.css
 *
 * Alamat selalu menyebut versinya, jadi browser boleh menyimpan file itu lama; saat website pindah versi, alamatnya
 * ikut berubah. Bila aset tidak dikenal atau belum punya versi terbit, dipakai alamat cadangan (argumen ketiga).

 */
final class AsetHelper
{
    private ?Katalog $katalog = null;

    /** @param Closure(): Katalog $buatKatalog katalog dibuat saat pertama dipakai */
    public function __construct(private readonly Closure $buatKatalog)
    {
    }

    public function __invoke(string $nama, string $file, ?string $cadangan = null): string
    {
        return $this->alamat($nama, $file) ?? $cadangan ?? '/asset/' . $nama . '/' . ltrim($file, '/');
    }

    /** Alamat file aset, atau null bila aset tidak dikenal, nonaktif, atau belum punya versi terbit. */
    public function alamat(string $nama, string $file): ?string
    {
        $aset = $this->katalog()->cari($nama);
        if ($aset === null || ! $aset->aktif) {
            return null;
        }

        $jalur = implode('/', array_map('rawurlencode', explode('/', trim($file, '/'))));
        if (! $aset->berversi) {
            $penuh = (new Penyimpanan($aset->folder))->fileUntukDilayani($file);

            return '/asset/' . $nama . '/' . $jalur . ($penuh === null ? '' : '?v=' . dechex((int) filemtime($penuh)));
        }

        $versi = $this->katalog()->versiUntuk($aset);

        return $versi === null ? null : '/asset/' . $nama . '/' . rawurlencode($versi) . '/' . $jalur;
    }

    /** Versi aset yang dipakai website ini, mis. '5.3.8'; null bila tidak ada. */
    public function versi(string $nama): ?string
    {
        $aset = $this->katalog()->cari($nama);

        return $aset === null ? null : $this->katalog()->versiUntuk($aset);
    }

    private function katalog(): Katalog
    {
        return $this->katalog ??= ($this->buatKatalog)();
    }
}
