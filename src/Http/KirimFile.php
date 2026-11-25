<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset\Http;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Mengirim satu file sebagai respons: ETag/Last-Modified (304), sebagian file (Range, 206), dan isi yang dialirkan per
 * 8 KB (AliranFile + EmitterAset), sehingga file besar seperti lagu atau video tidak dimuat ke memori. Dipakai modul aset
 * dan modul lain (mis. adems/dapur-berkas).
 */
final class KirimFile
{
    /** SVG bisa berisi skrip; dengan pembatas ini, dibuka langsung pun skripnya tidak dijalankan. */
    public const CSP_SVG = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox";

    /**
     * @param string      $contentType   jenis isi, mis. 'image/png'
     * @param string      $cacheControl  isi header Cache-Control
     * @param string|null $namaUnduh     bila diisi: dikirim sebagai unduhan (attachment) dengan nama ini
     * @param string|null $namaTampil    bila diisi (dan bukan unduhan): nama file untuk browser (inline)
     */
    public static function kirim(
        ServerRequestInterface $request,
        string $file,
        string $contentType,
        string $cacheControl,
        ?string $namaUnduh = null,
        ?string $namaTampil = null,
    ): ResponseInterface {
        $ukuran = (int) filesize($file);
        $waktu  = (int) filemtime($file);
        $etag   = sprintf('"%x-%x"', $ukuran, $waktu);
        $header = [
            'Content-Type'           => $contentType,
            'Cache-Control'          => $cacheControl,
            'ETag'                   => $etag,
            'Last-Modified'          => gmdate('D, d M Y H:i:s', $waktu) . ' GMT',
            'Accept-Ranges'          => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (str_starts_with($contentType, 'image/svg')) {
            $header['Content-Security-Policy'] = self::CSP_SVG;
        }

        if ($namaUnduh !== null || $namaTampil !== null) {
            $header['Content-Disposition'] = self::disposisi($namaUnduh !== null ? 'attachment' : 'inline', $namaUnduh ?? (string) $namaTampil);
        }

        if (self::belumBerubah($request, $etag, $waktu)) {
            return new EmptyResponse(304, array_diff_key($header, ['Content-Type' => 1, 'Accept-Ranges' => 1, 'Content-Disposition' => 1]));
        }

        $rentang = self::rentang($request, $ukuran, $etag);
        if ($rentang === false) {
            return new EmptyResponse(416, ['Content-Range' => "bytes */{$ukuran}"] + $header);
        }

        $isi = new AliranFile($file, 'rb');
        if ($rentang === null) {
            return new Response($isi, 200, ['Content-Length' => (string) $ukuran] + $header);
        }

        [$awal, $akhir] = $rentang;

        return new Response($isi, 206, [
            'Content-Range'  => "bytes {$awal}-{$akhir}/{$ukuran}",
            'Content-Length' => (string) ($akhir - $awal + 1),
        ] + $header);
    }

    /** Header Content-Disposition dengan nama file aman (RFC 6266: nama ASCII + filename* UTF-8). */
    public static function disposisi(string $jenis, string $nama): string
    {
        $nama  = str_replace(["\r", "\n", '"', '\\'], ['', '', "'", '_'], $nama);
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $nama) ?? 'berkas';

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $jenis, $ascii, rawurlencode($nama));
    }

    private static function belumBerubah(ServerRequestInterface $request, string $etag, int $waktu): bool
    {
        $cocok = $request->getHeaderLine('If-None-Match');
        if ($cocok !== '') {
            return trim($cocok) === '*'
                || in_array($etag, array_map(static fn (string $e): string => trim(str_replace('W/', '', $e)), explode(',', $cocok)), true);
        }

        $sejak = $request->getHeaderLine('If-Modified-Since');

        return $sejak !== '' && ($t = strtotime($sejak)) !== false && $t >= $waktu;
    }

    /**
     * Satu rentang dari header Range. null = kirim seluruh file; false = rentang tidak bisa dipenuhi (416).
     *
     * @return array{int, int}|false|null
     */
    private static function rentang(ServerRequestInterface $request, int $ukuran, string $etag): array|false|null
    {
        $range = $request->getHeaderLine('Range');
        $jika  = $request->getHeaderLine('If-Range');
        if ($range === '' || ($jika !== '' && $jika !== $etag) || preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) !== 1) {
            return null;
        }

        if ($m[1] === '' && $m[2] === '') {
            return null;
        }

        if ($m[1] === '') {
            $akhiran = (int) $m[2];

            return $akhiran === 0 ? false : [max(0, $ukuran - $akhiran), $ukuran - 1];
        }

        $awal  = (int) $m[1];
        $akhir = $m[2] === '' ? $ukuran - 1 : min((int) $m[2], $ukuran - 1);

        return $awal >= $ukuran || $awal > $akhir ? false : [$awal, $akhir];
    }
}
