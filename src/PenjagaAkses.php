<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Pemeriksa akses untuk aset ber-akses "login". Modul login (mis. dashboard dengan dapur-user) menyediakan layanan ini
 * di container dengan nama PenjagaAkses::class. Tanpa layanan ini, aset ber-akses login tidak pernah dilayani (404).
 */
interface PenjagaAkses
{
    /** true bila pengunjung boleh membuka aset ini (mis. sudah login dan punya akses ke website ini). */
    public function boleh(ServerRequestInterface $request, Aset $aset): bool;
}
