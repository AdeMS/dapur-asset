<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;

/**
 * Aset dari paket Composer yang terpasang:
 *
 * - twbs/bootstrap (folder dist) dan twbs/bootstrap-icons (folder font), yang dipasang inti Dapur;
 * - paket lain yang menyatakan foldernya di composer.json:
 *
 *       "extra": { "dapur": { "asset": { "adems/uang": "public" } } }
 *
 *   Versinya adalah versi paket yang terpasang.
 * - aset yang dinyatakan composer.json inti Dapur sendiri (paket root), mis. adems/dapur (CSS, JS, gambar layout).
 *   Aset ini TANPA versi (versi = null): versi paket root tidak bisa diandalkan (branch `2.12.x-dev`, atau
 *   `1.0.0+no-version-set` pada create-project), jadi alamatnya memakai `?v=<waktu ubah file>` dari helper aset().
 */
final class AsetComposer
{
    /** @var array<string, array{paket: string, folder: string}> */
    public const BAWAAN = [
        'twbs/bootstrap'       => ['paket' => 'twbs/bootstrap', 'folder' => 'dist'],
        'twbs/bootstrap-icons' => ['paket' => 'twbs/bootstrap-icons', 'folder' => 'font'],
    ];

    /** Penanda bahwa versi ini mengenal aset milik inti (dipakai bin/setup-bootstrap.php inti Dapur 2.12). */
    public const ASET_INTI = true;

    /** @var array<string, array{paket: string, folder: string, versi: ?string}>|null */
    private ?array $daftar = null;

    /**
     * @param string|null $fileInstalled vendor/composer/installed.json; null = dicari dari autoloader Composer
     * @param array<string, array{paket: string, folder: string, versi: ?string}>|null $daftar daftar tetap (untuk uji)
     * @param string|null $folderInti folder inti Dapur (composer.json root); null = folder paket root Composer
     */
    public function __construct(
        private readonly ?string $fileInstalled = null,
        ?array $daftar = null,
        private readonly ?string $folderInti = null,
    ) {
        $this->daftar = $daftar;
    }

    /**
     * @return array<string, array{paket: string, folder: string, versi: ?string}> nama aset => paket, folder, versi
     *         (versi null = aset tanpa versi milik inti)
     */
    public function daftar(): array
    {
        return $this->daftar ??= $this->baca();
    }

    /** @return array<string, array{paket: string, folder: string, versi: ?string}> */
    private function baca(): array
    {
        $hasil = $this->asetInti();
        foreach (self::BAWAAN as $nama => $isi) {
            $aset = $this->aset($isi['paket'], $isi['folder']);
            if ($aset !== null && ! isset($hasil[$nama])) {
                $hasil[$nama] = $aset;
            }
        }

        foreach ($this->paketDenganAset() as $paket => $daftar) {
            foreach ($daftar as $nama => $folder) {
                if (! is_string($nama) || ! is_string($folder) || ! Nama::sahAset($nama) || isset($hasil[$nama])) {
                    continue;
                }

                $aset = $this->aset($paket, $folder);
                if ($aset !== null) {
                    $hasil[$nama] = $aset;
                }
            }
        }

        ksort($hasil);

        return $hasil;
    }

    /** @return array{paket: string, folder: string, versi: string}|null */
    private function aset(string $paket, string $folder): ?array
    {
        if (! InstalledVersions::isInstalled($paket)) {
            return null;
        }

        $dasar = InstalledVersions::getInstallPath($paket);
        $versi = Nama::normalkanVersi((string) InstalledVersions::getPrettyVersion($paket));
        if ($dasar === null || ! Nama::sahVersi($versi)) {
            return null;
        }

        try {
            $relatif = Nama::periksaJalur($folder);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $penuh = realpath($dasar . ($relatif === '' ? '' : '/' . $relatif));

        return $penuh === false || ! is_dir($penuh) ? null : ['paket' => $paket, 'folder' => $penuh, 'versi' => $versi];
    }

    /**
     * Aset dari composer.json inti Dapur. Foldernya harus berada di dalam folder inti.
     *
     * @return array<string, array{paket: string, folder: string, versi: null}>
     */
    private function asetInti(): array
    {
        try {
            $inti = $this->folderInti ?? Lingkup::folderInti();
        } catch (\Throwable) {
            return [];
        }

        $isi = is_file($inti . '/composer.json') ? json_decode((string) file_get_contents($inti . '/composer.json'), true) : null;
        $daftar = is_array($isi) ? ($isi['extra']['dapur']['asset'] ?? null) : null;
        if (! is_array($daftar)) {
            return [];
        }

        $akar  = realpath($inti);
        $paket = is_string($isi['name'] ?? null) ? $isi['name'] : 'root';
        $hasil = [];
        foreach ($daftar as $nama => $folder) {
            if (! is_string($nama) || ! is_string($folder) || ! Nama::sahAset($nama, true) || $akar === false) {
                continue;
            }

            try {
                $relatif = Nama::periksaJalur($folder);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $penuh = $relatif === '' ? false : realpath($akar . '/' . $relatif);
            if ($penuh !== false && is_dir($penuh) && str_starts_with($penuh . '/', $akar . '/')) {
                $hasil[$nama] = ['paket' => $paket, 'folder' => $penuh, 'versi' => null];
            }
        }

        return $hasil;
    }

    /** @return array<string, array<mixed>> nama paket => isi extra.dapur.asset */
    private function paketDenganAset(): array
    {
        $file = $this->fileInstalled ?? self::cariInstalled();
        if ($file === null || ! is_file($file)) {
            return [];
        }

        $isi    = json_decode((string) file_get_contents($file), true);
        $paket  = is_array($isi) ? ($isi['packages'] ?? $isi) : [];
        $hasil  = [];
        foreach (is_array($paket) ? $paket : [] as $p) {
            $aset = $p['extra']['dapur']['asset'] ?? null;
            if (is_array($p) && is_string($p['name'] ?? null) && is_array($aset)) {
                $hasil[$p['name']] = $aset;
            }
        }

        return $hasil;
    }

    private static function cariInstalled(): ?string
    {
        foreach (array_keys(ClassLoader::getRegisteredLoaders()) as $vendor) {
            if (is_file($vendor . '/composer/installed.json')) {
                return $vendor . '/composer/installed.json';
            }
        }

        return null;
    }
}
