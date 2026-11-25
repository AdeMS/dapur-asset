<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

/**
 * Jenis file yang boleh disimpan dan dilayani sebagai aset. File lain (termasuk .php, .phtml, .phar, .html) ditolak
 * saat diunggah dan tidak pernah dilayani.
 */
final class JenisFile
{
    /** @var array<string, string> akhiran => Content-Type */
    public const DAFTAR = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'text/javascript; charset=utf-8',
        'mjs'   => 'text/javascript; charset=utf-8',
        'map'   => 'application/json; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'txt'   => 'text/plain; charset=utf-8',
        'md'    => 'text/plain; charset=utf-8',
        'csv'   => 'text/csv; charset=utf-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'eot'   => 'application/vnd.ms-fontobject',
        'pdf'   => 'application/pdf',
        'mp3'   => 'audio/mpeg',
        'm4a'   => 'audio/mp4',
        'ogg'   => 'audio/ogg',
        'oga'   => 'audio/ogg',
        'wav'   => 'audio/wav',
        'flac'  => 'audio/flac',
        'mp4'   => 'video/mp4',
        'webm'  => 'video/webm',
    ];

    public static function akhiran(string $file): string
    {
        return strtolower(pathinfo($file, PATHINFO_EXTENSION));
    }

    public static function diizinkan(string $file): bool
    {
        return isset(self::DAFTAR[self::akhiran($file)]);
    }

    public static function contentType(string $file): ?string
    {
        return self::DAFTAR[self::akhiran($file)] ?? null;
    }

    /** Daftar akhiran untuk ditampilkan, mis. "css, js, png, ...". */
    public static function teks(): string
    {
        return implode(', ', array_keys(self::DAFTAR));
    }
}
