<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use InvalidArgumentException;

/**
 * Aturan nama aset, versi, dan jalur file di dalam aset.
 *
 * Nama aset berbentuk `<vendor>/<aplikasi>`, misalnya `twbs/bootstrap` atau `adems/uang`, sehingga alamat publiknya
 * `/asset/<vendor>/<aplikasi>/[<versi>/]<file>`.
 */
final class Nama
{
    /** Satu bagian nama aset: huruf kecil, angka, titik, garis bawah, dan tanda hubung, diawali huruf atau angka. */
    private const BAGIAN = '[a-z0-9][a-z0-9._-]{0,49}';

    /** Nomor versi, misalnya 5.3.8, 1.0.0-beta.1, atau 2026-11. */
    private const VERSI = '/^[0-9A-Za-z][0-9A-Za-z._+-]{0,39}$/';

    /**
     * Vendor milik inti Dapur untuk alamat lama sampai Dapur 3.0: `dapur` (/asset/dapur/..., kini aset adems/dapur) dan
     * `bootstrap` (/asset/bootstrap/...). Keduanya tidak boleh dipakai aset dari dashboard atau file konfigurasi; aset
     * dari composer.json inti tetap boleh.
     */
    public const VENDOR_TERPAKAI = ['dapur', 'bootstrap'];

    /** @param bool $milikInti true untuk aset yang dinyatakan composer.json inti Dapur (boleh memakai vendor inti) */
    public static function sahAset(string $nama, bool $milikInti = false): bool
    {
        if (preg_match('#^' . self::BAGIAN . '/' . self::BAGIAN . '$#', $nama) !== 1 || str_contains($nama, '..')) {
            return false;
        }

        return $milikInti || ! in_array(explode('/', $nama)[0], self::VENDOR_TERPAKAI, true);
    }

    public static function periksaAset(string $nama): string
    {
        $nama = strtolower(trim($nama));
        if (! self::sahAset($nama)) {
            throw new InvalidArgumentException(
                'Nama aset harus berbentuk vendor/aplikasi (huruf kecil, angka, titik, garis bawah, tanda hubung), '
                . 'misalnya adems/uang. Vendor ' . implode(' dan ', self::VENDOR_TERPAKAI) . ' sudah dipakai inti Dapur.'
            );
        }

        return $nama;
    }

    public static function sahVersi(string $versi): bool
    {
        return preg_match(self::VERSI, $versi) === 1 && ! str_contains($versi, '..');
    }

    public static function periksaVersi(string $versi): string
    {
        $versi = self::normalkanVersi($versi);
        if (! self::sahVersi($versi)) {
            throw new InvalidArgumentException('Versi hanya boleh huruf, angka, titik, garis bawah, plus, dan tanda hubung (maksimal 40), misalnya 5.3.8.');
        }

        return $versi;
    }

    /** Versi dari Composer bisa berawalan 'v' (v5.3.8); disimpan tanpa awalan. */
    public static function normalkanVersi(string $versi): string
    {
        $versi = trim($versi);

        return preg_match('/^v\d/', $versi) === 1 ? substr($versi, 1) : $versi;
    }

    /**
     * Memeriksa satu bagian jalur (nama folder atau file). Ditolak: kosong, '.', '..', diawali titik (file tersembunyi
     * seperti .htaccess) atau '@' (folder sistem NAS seperti @eaDir), garis miring terbalik, karakter kendali, atau
     * lebih dari 255 byte.
     */
    public static function sahBagianJalur(string $bagian): bool
    {
        return $bagian !== ''
            && strlen($bagian) <= 255
            && ! str_starts_with($bagian, '.')
            && ! str_starts_with($bagian, '@')
            && ! str_contains($bagian, '\\')
            && preg_match('/[\x00-\x1f\x7f]/', $bagian) !== 1;
    }

    /**
     * Jalur relatif di dalam aset, mis. 'css/bootstrap.min.css'. '' berarti akar aset.
     *
     * @return list<string> bagian-bagian jalur
     */
    public static function bagianJalur(string $jalur): array
    {
        $jalur = trim(str_replace('\\', '/', $jalur), '/');
        if ($jalur === '') {
            return [];
        }

        $bagian = explode('/', $jalur);
        foreach ($bagian as $b) {
            if (! self::sahBagianJalur($b)) {
                throw new InvalidArgumentException("Nama folder atau file tidak diizinkan: {$b}");
            }
        }

        return $bagian;
    }

    public static function periksaJalur(string $jalur): string
    {
        return implode('/', self::bagianJalur($jalur));
    }

    /** Mengurutkan versi dari yang terbaru. */
    public static function urutkanTerbaru(array $versi): array
    {
        usort($versi, static fn (string $a, string $b): int => version_compare($b, $a) ?: strcmp($b, $a));

        return array_values($versi);
    }
}
