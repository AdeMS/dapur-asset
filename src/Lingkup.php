<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use AdeMS\Dapur\MultiWebsite\Website;
use Composer\InstalledVersions;

/** Website yang sedang dilayani: 'dashboard' untuk inti Dapur, atau nama domain website (paket multi-website). */
final class Lingkup
{
    public const UTAMA = 'dashboard';

    public static function sekarang(): string
    {
        return (class_exists(Website::class) ? Website::aktif()?->host : null) ?? self::UTAMA;
    }

    public static function folderInti(): string
    {
        $folder = InstalledVersions::getRootPackage()['install_path'];

        return rtrim((string) (realpath($folder) ?: $folder), '/');
    }
}
