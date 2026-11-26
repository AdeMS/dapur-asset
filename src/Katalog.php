<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use Throwable;

/**
 * Semua aset yang dikenal Dapur, dari tiga sumber: paket Composer, file konfigurasi, dan database (dashboard).
 * Bila nama sama, aset dari file konfigurasi menang, lalu Composer, lalu database.
 *
 * Versi yang dipakai saat alamat tidak menyebut versi: pilihan website (file konfigurasi website, lalu dashboard),
 * lalu versi bawaan aset, lalu versi terbit yang terbaru.
 */
final class Katalog
{
    /** @var array<string, Aset>|null */
    private ?array $aset = null;

    /** @var array<string, string>|null */
    private ?array $pilihan = null;

    public function __construct(
        private readonly Pengaturan $pengaturan,
        private readonly AsetComposer $composer,
        private readonly ?DaftarAset $daftar,
        private readonly string $lingkup = Lingkup::UTAMA,
    ) {
    }

    /** @return array<string, Aset> nama => aset, urut nama */
    public function semua(): array
    {
        return $this->aset ??= $this->susun();
    }

    /** Membaca ulang daftar aset (sesudah ada perubahan). */
    public function segarkan(): void
    {
        $this->aset    = null;
        $this->pilihan = null;
    }

    public function cari(string $nama): ?Aset
    {
        return $this->semua()[$nama] ?? null;
    }

    /** Folder aset dari dashboard: <folder aset>/<vendor>/<aplikasi>. */
    public function folderAset(string $nama): string
    {
        return $this->pengaturan->folder . '/' . $nama;
    }

    /** Versi yang dipakai website ini bila alamat tidak menyebut versi; null bila belum ada versi terbit. */
    public function versiUntuk(Aset $aset, ?string $lingkup = null): ?string
    {
        if (! $aset->berversi) {
            return null;
        }

        $calon = [];
        if ($lingkup === null || $lingkup === $this->lingkup) {
            $calon[] = $this->pengaturan->versiWebsite[$aset->nama] ?? null;
            $calon[] = $this->pilihanWebsite()[$aset->nama] ?? null;
        } elseif ($this->daftar !== null && $aset->id !== null) {
            $calon[] = $this->daftar->pilihanAset($aset->id)[$lingkup] ?? null;
        }
        $calon[] = $aset->versiBawaan;

        foreach ($calon as $versi) {
            if ($versi !== null && $aset->adaVersiTerbit($versi)) {
                return $versi;
            }
        }

        return $aset->versiTerbit()[0] ?? null;
    }

    /** @return array<string, Aset> */
    private function susun(): array
    {
        $baris = [];
        $versi = [];
        if ($this->daftar?->tersedia()) {
            try {
                $baris = $this->daftar->semua();
                $versi = $this->daftar->semuaVersi();
            } catch (Throwable $e) {
                error_log('dapur-asset: daftar aset tidak bisa dibaca: ' . $e->getMessage());
            }
        }

        $hasil = [];

        // Database (dashboard).
        foreach ($baris as $nama => $b) {
            if (! Nama::sahAset($nama)) {
                continue;
            }

            $id       = (int) $b['id'];
            $berversi = (int) $b['berversi'] === 1;
            $folder   = $this->folderAset($nama);
            $hasil[$nama] = new Aset(
                nama: $nama,
                sumber: Aset::SUMBER_DATABASE,
                id: $id,
                berversi: $berversi,
                akses: $b['akses'] === Aset::LOGIN ? Aset::LOGIN : Aset::PUBLIK,
                aktif: (int) $b['aktif'] === 1,
                versiBawaan: is_string($b['versi_bawaan'] ?? null) ? $b['versi_bawaan'] : null,
                keterangan: (string) ($b['keterangan'] ?? ''),
                folder: $folder,
                versi: $berversi ? $this->versiUnggahan($folder, $versi[$id] ?? []) : [],
            );
        }

        // Composer: versi paket yang terpasang, ditambah versi unggahan bila aset ini juga tercatat di database.
        foreach ($this->composer->daftar() as $nama => $c) {
            $db = $hasil[$nama] ?? null;

            // Aset milik inti Dapur (composer.json inti): tanpa versi, langsung dari folder di repo inti.
            if ($c['versi'] === null) {
                $hasil[$nama] = new Aset(
                    nama: $nama,
                    sumber: Aset::SUMBER_COMPOSER,
                    id: null,
                    berversi: false,
                    akses: Aset::PUBLIK,
                    aktif: true,
                    versiBawaan: null,
                    keterangan: 'Bawaan inti Dapur',
                    folder: $c['folder'],
                    paket: $c['paket'],
                );

                continue;
            }

            $semua    = [$c['versi'] => new Versi($c['versi'], $c['folder'], Aset::SUMBER_COMPOSER, true)];
            foreach ($db?->versi ?? [] as $v => $isi) {
                $semua[$v] ??= $isi;
            }

            $hasil[$nama] = new Aset(
                nama: $nama,
                sumber: Aset::SUMBER_COMPOSER,
                id: $db?->id,
                berversi: true,
                akses: Aset::PUBLIK,
                aktif: true,
                versiBawaan: $db?->versiBawaan ?? $c['versi'],
                keterangan: $db?->keterangan ?? '',
                folder: $this->folderAset($nama),
                versi: $semua,
                paket: $c['paket'],
            );
        }

        // File konfigurasi.
        foreach ($this->pengaturan->asetTerkunci as $nama => $f) {
            $semua = [];
            if ($f['berversi'] && is_dir($f['folder'])) {
                foreach (scandir($f['folder']) ?: [] as $v) {
                    if (Nama::sahVersi($v) && is_dir($f['folder'] . '/' . $v)) {
                        $semua[$v] = new Versi($v, $f['folder'] . '/' . $v, Aset::SUMBER_FILE, true);
                    }
                }
            }

            $hasil[$nama] = new Aset(
                nama: $nama,
                sumber: Aset::SUMBER_FILE,
                id: null,
                berversi: $f['berversi'],
                akses: $f['akses'],
                aktif: true,
                versiBawaan: $f['versi_bawaan'],
                keterangan: $f['keterangan'],
                folder: $f['folder'],
                versi: $semua,
            );
        }

        ksort($hasil);

        return $hasil;
    }

    /**
     * Versi unggahan: folder versi yang tercatat di database. Folder yang tidak tercatat (mis. disalin lewat terminal)
     * ikut sebagai versi belum terbit, agar bisa diterbitkan dari dashboard.
     *
     * @param array<string, ?string> $catatan versi => terbit_pada
     * @return array<string, Versi>
     */
    private function versiUnggahan(string $folder, array $catatan): array
    {
        $hasil = [];
        foreach ($catatan as $v => $terbit) {
            $hasil[$v] = new Versi($v, $folder . '/' . $v, Aset::SUMBER_DATABASE, $terbit !== null && is_dir($folder . '/' . $v));
        }

        foreach (is_dir($folder) ? (scandir($folder) ?: []) : [] as $v) {
            if (! isset($hasil[$v]) && Nama::sahVersi($v) && is_dir($folder . '/' . $v)) {
                $hasil[$v] = new Versi($v, $folder . '/' . $v, Aset::SUMBER_DATABASE, false);
            }
        }

        return $hasil;
    }

    /** @return array<string, string> */
    private function pilihanWebsite(): array
    {
        if ($this->pilihan !== null) {
            return $this->pilihan;
        }

        try {
            return $this->pilihan = $this->daftar?->tersedia() ? $this->daftar->pilihanWebsite($this->lingkup) : [];
        } catch (Throwable $e) {
            error_log('dapur-asset: pilihan versi website tidak bisa dibaca: ' . $e->getMessage());

            return $this->pilihan = [];
        }
    }
}
