<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset\Handler;

use AdeMS\Dapur\Asset\Aset;
use AdeMS\Dapur\Asset\Http\KirimFile;
use AdeMS\Dapur\Asset\JenisFile;
use AdeMS\Dapur\Asset\Katalog;
use AdeMS\Dapur\Asset\PenjagaAkses;
use AdeMS\Dapur\Asset\Penyimpanan;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Melayani `/asset/<vendor>/<aplikasi>/[<versi>/]<file>`.
 *
 * - Alamat dengan versi: isinya tidak pernah berubah (versi terbit dikunci), jadi browser boleh menyimpannya setahun.
 * - Alamat tanpa versi: versi pilihan website (atau versi bawaan, atau terbaru); disimpan browser 5 menit saja.
 * - Aset ber-akses login hanya dilayani bila PenjagaAkses mengizinkan; selain itu 404, agar keberadaannya tidak
 *   ketahuan.
 *
 * File yang ada di public/asset (mis. /asset/dapur/...) dilayani web server langsung dan tidak pernah sampai ke sini.
 */
final class AsetHandler implements RequestHandlerInterface
{
    /**
     * Alamat lama sebelum modul aset: /asset/bootstrap/... (Bootstrap) dan /asset/bootstrap/font/... (Bootstrap Icons),
     * dari salinan yang dulu dibuat composer di public/asset/bootstrap. Kini dilayani dari aset Composer, dengan versi
     * yang dipakai website ini. Tetap didukung sampai Dapur 3.0.
     *
     * @var array<string, string> awalan alamat lama => nama aset
     */
    public const ALAMAT_LAMA = [
        'bootstrap/font/' => 'twbs/bootstrap-icons/',
        'bootstrap/'      => 'twbs/bootstrap/',
    ];

    public const CACHE_TETAP   = 31536000;
    public const CACHE_SEMENTARA = 300;

    public function __construct(
        private readonly Katalog $katalog,
        private readonly ?PenjagaAkses $penjaga = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $jalur  = self::alamatBaru(rawurldecode((string) $request->getAttribute('jalur', '')));
        $bagian = explode('/', trim($jalur, '/'));
        if (count($bagian) < 3) {
            return self::tidakAda();
        }

        $aset = $this->katalog->cari($bagian[0] . '/' . $bagian[1]);
        $sisa = array_slice($bagian, 2);
        if ($aset === null || ! $aset->aktif) {
            return self::tidakAda();
        }

        if ($aset->akses === Aset::LOGIN && ($this->penjaga === null || ! $this->penjaga->boleh($request, $aset))) {
            return self::tidakAda();
        }

        $tetap = false;
        $versi = null;
        if ($aset->berversi) {
            if (count($sisa) > 1 && $aset->adaVersiTerbit($sisa[0])) {
                $versi = array_shift($sisa);
                $tetap = true;
            } else {
                $versi = $this->katalog->versiUntuk($aset);
            }

            if ($versi === null) {
                return self::tidakAda();
            }
        }

        $folder = $aset->folderIsi($versi);
        $file   = $folder === null ? null : (new Penyimpanan($folder))->fileUntukDilayani(implode('/', $sisa));
        if ($file === null) {
            return self::tidakAda();
        }

        return $this->kirim($request, $file, $tetap, $aset->akses === Aset::LOGIN);
    }

    private static function alamatBaru(string $jalur): string
    {
        foreach (self::ALAMAT_LAMA as $lama => $baru) {
            if (str_starts_with($jalur, $lama)) {
                return $baru . substr($jalur, strlen($lama));
            }
        }

        return $jalur;
    }

    private function kirim(ServerRequestInterface $request, string $file, bool $tetap, bool $pribadi): ResponseInterface
    {
        return KirimFile::kirim(
            $request,
            $file,
            (string) JenisFile::contentType($file),
            sprintf('%s, max-age=%d%s', $pribadi ? 'private' : 'public', $tetap ? self::CACHE_TETAP : self::CACHE_SEMENTARA, $tetap ? ', immutable' : ''),
        );
    }

    private static function tidakAda(): ResponseInterface
    {
        return new TextResponse('Aset tidak ditemukan.', 404, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
    }
}
