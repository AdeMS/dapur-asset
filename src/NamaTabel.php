<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use InvalidArgumentException;

/** Nama tabel modul aset, mengikuti awalan tabel instalasi (`'database' => ['awalan_tabel' => ...]`). */
final class NamaTabel
{
    public readonly string $aset;
    public readonly string $versi;
    public readonly string $website;
    public readonly string $riwayat;

    public function __construct(public readonly string $awalan = '')
    {
        if (preg_match('/^[A-Za-z0-9_]{0,32}$/', $awalan) !== 1) {
            throw new InvalidArgumentException('Awalan tabel hanya boleh huruf, angka, dan garis bawah (maksimal 32).');
        }

        $this->aset    = $awalan . 'asset';
        $this->versi   = $awalan . 'asset_versi';
        $this->website = $awalan . 'asset_website';
        $this->riwayat = $awalan . 'asset_riwayat';
    }
}
