<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

/**
 * Satu aset, misalnya twbs/bootstrap.
 *
 * Sumber aset:
 * - composer: paket Composer (Bootstrap, Bootstrap Icons, atau modul yang menyatakan `extra.dapur.asset`), terkunci;
 * - file: ditulis di file konfigurasi `'asset' => ['aset' => [...]]`, terkunci;
 * - database: dibuat dari dashboard, filenya di folder aset (bawaan data/asset/<vendor>/<aplikasi>/).
 *
 * Aset Composer juga boleh punya catatan di database, untuk versi tambahan yang diunggah dan versi bawaannya.
 */
final class Aset
{
    public const PUBLIK = 'publik';
    public const LOGIN  = 'login';

    public const SUMBER_COMPOSER = 'composer';
    public const SUMBER_FILE     = 'file';
    public const SUMBER_DATABASE = 'database';

    /**
     * @param array<string, Versi> $versi semua versi (termasuk yang belum terbit), kosong untuk aset tanpa versi
     */
    public function __construct(
        public readonly string $nama,
        public readonly string $sumber,
        public readonly ?int $id,
        public readonly bool $berversi,
        public readonly string $akses,
        public readonly bool $aktif,
        public readonly ?string $versiBawaan,
        public readonly string $keterangan,
        public readonly string $folder,
        public readonly array $versi = [],
        public readonly ?string $paket = null,
    ) {
    }

    /** Pengaturan aset (akses, aktif, keterangan) hanya bisa diubah untuk aset yang dibuat dari dashboard. */
    public function terkunci(): bool
    {
        return $this->sumber !== self::SUMBER_DATABASE;
    }

    /** Versi tambahan bisa diunggah ke aset berversi dari dashboard, dan ke aset dari paket Composer. */
    public function bisaTambahVersi(): bool
    {
        return $this->berversi && $this->sumber !== self::SUMBER_FILE;
    }

    /** Isi aset tanpa versi boleh diubah bila aset dibuat dari dashboard. */
    public function isiDapatDiubah(): bool
    {
        return ! $this->berversi && $this->sumber === self::SUMBER_DATABASE;
    }

    /** @return list<string> versi yang sudah terbit, dari yang terbaru */
    public function versiTerbit(): array
    {
        return Nama::urutkanTerbaru(array_keys(array_filter($this->versi, static fn (Versi $v): bool => $v->terbit)));
    }

    public function adaVersiTerbit(string $versi): bool
    {
        return ($this->versi[$versi] ?? null)?->terbit === true;
    }

    /** Folder isi aset: folder versi untuk aset berversi, atau folder aset untuk aset tanpa versi. */
    public function folderIsi(?string $versi): ?string
    {
        if (! $this->berversi) {
            return $this->folder;
        }

        return $versi === null ? null : ($this->versi[$versi] ?? null)?->folder;
    }
}
