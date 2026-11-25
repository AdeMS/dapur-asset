<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset\Test;

use AdeMS\Dapur\Asset\Aset;
use AdeMS\Dapur\Asset\DaftarAset;
use AdeMS\Dapur\Asset\GagalAset;
use PHPUnit\Framework\TestCase;

final class KelolaAsetTest extends TestCase
{
    private Bantu $b;

    protected function setUp(): void
    {
        $this->b = new Bantu();
    }

    protected function tearDown(): void
    {
        $this->b->hapus();
    }

    public function testAsetBerversiDariDashboard(): void
    {
        $k    = $this->b->kelola();
        $aset = $k->tambah('Adems/Uang', true, 'publik', 'Gaya Uang AdeMS');
        self::assertSame('adems/uang', $aset->nama);
        self::assertSame(Aset::SUMBER_DATABASE, $aset->sumber);
        self::assertDirectoryExists($this->b->folder . '/data/asset/adems/uang');

        $k->tambahVersi('adems/uang', '1.0.0');
        try {
            $k->terbitkan('adems/uang', '1.0.0');
            self::fail('Versi kosong tidak boleh terbit');
        } catch (GagalAset $e) {
            self::assertStringContainsString('kosong', $e->getMessage());
        }

        $k->simpanUnggahan('adems/uang', '1.0.0', 'css', 'uang.css', $this->b->file('tmp/a', 'body{}'), false);
        $aset = $k->terbitkan('adems/uang', '1.0.0');
        self::assertTrue($aset->adaVersiTerbit('1.0.0'));

        // Versi terbit terkunci.
        $this->expectException(GagalAset::class);
        $k->simpanUnggahan('adems/uang', '1.0.0', 'css', 'lain.css', $this->b->file('tmp/b', 'x'), false);
    }

    public function testUrutanPemilihanVersi(): void
    {
        $k = $this->b->kelola();
        $k->tambah('adems/uang', true, 'publik');
        foreach (['1.0.0', '2.0.0', '1.5.0'] as $v) {
            $k->tambahVersi('adems/uang', $v);
            $k->simpanUnggahan('adems/uang', $v, '', 'a.css', $this->b->file('tmp/' . $v, $v), false);
            $k->terbitkan('adems/uang', $v);
        }
        $k->tambahVersi('adems/uang', '3.0.0'); // belum terbit: tidak pernah dipakai

        $katalog = $this->b->katalog('toko.contoh.test');
        $aset    = $katalog->cari('adems/uang');
        self::assertSame('2.0.0', $katalog->versiUntuk($aset), 'tanpa pilihan: terbaru yang terbit');

        $aset = $k->aturVersiBawaan('adems/uang', '1.5.0');
        $katalog->segarkan();
        self::assertSame('1.5.0', $katalog->versiUntuk($katalog->cari('adems/uang')), 'versi bawaan aset');

        $k->pilihVersi('adems/uang', 'toko.contoh.test', '1.0.0');
        $katalog->segarkan();
        self::assertSame('1.0.0', $katalog->versiUntuk($katalog->cari('adems/uang')), 'pilihan website menang');
        self::assertSame('1.5.0', $this->b->katalog('lain.contoh.test')->versiUntuk($katalog->cari('adems/uang')));
        self::assertSame('1.0.0', $this->b->katalog()->versiUntuk($katalog->cari('adems/uang'), 'toko.contoh.test'));

        // Pengaturan file website menang atas pilihan dashboard.
        $this->b->config = ['versi' => ['adems/uang' => 'v2.0.0']];
        self::assertSame('2.0.0', $this->b->katalog('toko.contoh.test')->versiUntuk($katalog->cari('adems/uang')));

        try {
            $k->pilihVersi('adems/uang', 'toko.contoh.test', '3.0.0');
            self::fail('Versi belum terbit tidak bisa dipilih');
        } catch (GagalAset) {
            self::assertTrue(true);
        }

        $k->hapusVersi('adems/uang', '3.0.0');
        self::assertDirectoryDoesNotExist($this->b->folder . '/data/asset/adems/uang/3.0.0');

        $this->expectException(GagalAset::class);
        $k->hapusVersi('adems/uang', '2.0.0');
    }

    public function testAsetComposerTerkunciTetapiBisaDiberiVersiTambahan(): void
    {
        mkdir($this->b->folder . '/vendor/twbs/bootstrap/dist/css', 0777, true);
        file_put_contents($this->b->folder . '/vendor/twbs/bootstrap/dist/css/bootstrap.min.css', '5.3.8');
        $this->b->composer = ['twbs/bootstrap' => ['paket' => 'twbs/bootstrap', 'folder' => $this->b->folder . '/vendor/twbs/bootstrap/dist', 'versi' => '5.3.8']];

        $k    = $this->b->kelola();
        $aset = $k->aset('twbs/bootstrap');
        self::assertTrue($aset->terkunci());
        self::assertSame('5.3.8', $aset->versiBawaan);
        self::assertNull($aset->id);

        try {
            $k->ubah('twbs/bootstrap', 'login', true, '');
            self::fail('Aset Composer terkunci');
        } catch (GagalAset) {
            self::assertTrue(true);
        }

        try {
            $k->simpanUnggahan('twbs/bootstrap', '5.3.8', 'css', 'x.css', $this->b->file('tmp/x', 'x'), false);
            self::fail('Versi Composer tidak bisa diubah');
        } catch (GagalAset) {
            self::assertTrue(true);
        }

        $k->tambahVersi('twbs/bootstrap', '5.4.0');
        $k->simpanUnggahan('twbs/bootstrap', '5.4.0', 'css', 'bootstrap.min.css', $this->b->file('tmp/y', '5.4.0'), false);
        $aset = $k->terbitkan('twbs/bootstrap', '5.4.0');
        self::assertNotNull($aset->id, 'dicatat di database saat diberi versi tambahan');
        self::assertSame(Aset::SUMBER_COMPOSER, $aset->sumber);
        self::assertSame('5.3.8', $this->b->katalog()->versiUntuk($aset), 'versi Composer tetap bawaan walau ada versi lebih baru');

        $k->pilihVersi('twbs/bootstrap', 'toko.contoh.test', '5.4.0');
        self::assertSame('5.4.0', $this->b->katalog('toko.contoh.test')->versiUntuk($aset));
    }

    public function testAsetTanpaVersiDanHapus(): void
    {
        $k = $this->b->kelola();
        $k->tambah('adems/foto', false, 'login');
        $k->buatFolder('adems/foto', null, 'galeri');
        $k->simpanUnggahan('adems/foto', null, 'galeri', 'a.jpg', $this->b->file('tmp/a', 'jpg'), false);
        $k->gantiNama('adems/foto', null, 'galeri/a.jpg', 'b.jpg');
        $k->hapusFile('adems/foto', null, 'galeri/b.jpg');

        $aset = $k->ubah('adems/foto', 'publik', false, 'Galeri');
        self::assertFalse($aset->aktif);
        self::assertSame(Aset::PUBLIK, $aset->akses);

        $this->expectExceptionMessage('tidak memakai versi');
        try {
            $k->tambahVersi('adems/foto', '1');
        } finally {
            $peristiwa = array_column($this->b->daftar->riwayat('adems/foto'), 'peristiwa');
            self::assertSame([DaftarAset::UBAH, DaftarAset::HAPUS_FILE, DaftarAset::GANTI_NAMA, DaftarAset::UNGGAH, DaftarAset::BUAT_FOLDER, DaftarAset::TAMBAH], $peristiwa);
            self::assertSame('admin@contoh.test', $this->b->daftar->riwayat('adems/foto')[0]['pelaku']);

            $k->hapus('adems/foto');
            self::assertNull($this->b->katalog()->cari('adems/foto'));
            self::assertDirectoryDoesNotExist($this->b->folder . '/data/asset/adems/foto');
        }
    }

    public function testAsetDariFileKonfigurasiMenang(): void
    {
        $this->b->kelola()->tambah('adems/logo', false, 'publik');
        mkdir($this->b->folder . '/logo');
        $this->b->config = ['aset' => ['adems/logo' => ['folder' => $this->b->folder . '/logo', 'akses' => 'login']]];

        $aset = $this->b->katalog()->cari('adems/logo');
        self::assertSame(Aset::SUMBER_FILE, $aset->sumber);
        self::assertSame(Aset::LOGIN, $aset->akses);
        self::assertTrue($aset->terkunci());

        $this->expectException(GagalAset::class);
        $this->b->kelola()->hapus('adems/logo');
    }

    public function testSalinVersi(): void
    {
        $k = $this->b->kelola();
        $k->tambah('adems/uang', true, 'publik');
        $k->tambahVersi('adems/uang', '1.0.0');
        $k->simpanUnggahan('adems/uang', '1.0.0', 'css', 'a.css', $this->b->file('tmp/a', 'a'), false);
        $k->terbitkan('adems/uang', '1.0.0');

        $aset = $k->tambahVersi('adems/uang', '1.0.1', '1.0.0');
        self::assertFalse($aset->versi['1.0.1']->terbit);
        self::assertFileExists($this->b->folder . '/data/asset/adems/uang/1.0.1/css/a.css');
    }
}
