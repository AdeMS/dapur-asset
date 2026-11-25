<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

/** Satu versi aset berversi. */
final class Versi
{
    public function __construct(
        public readonly string $nama,
        public readonly string $folder,
        public readonly string $sumber,
        public readonly bool $terbit,
    ) {
    }

    /** Hanya versi unggahan (dari dashboard) yang belum diterbitkan yang isinya boleh diubah. */
    public function dapatDiubah(): bool
    {
        return $this->sumber === Aset::SUMBER_DATABASE && ! $this->terbit;
    }
}
