<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset\Test;

use AdeMS\Dapur\Asset\Aset;
use AdeMS\Dapur\Asset\AsetComposer;
use AdeMS\Dapur\Asset\AsetHelper;
use AdeMS\Dapur\Asset\Handler\AsetHandler;
use AdeMS\Dapur\Asset\Katalog;
use AdeMS\Dapur\Asset\Nama;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/** Aset milik inti Dapur, dinyatakan di composer.json inti (`extra.dapur.asset`). */
final class AsetIntiTest extends TestCase
{
    private Bantu $b;
    private string $inti;

    protected function setUp(): void
    {
        $this->b    = new Bantu();
        $this->inti = $this->b->folder . '/inti';
        $this->b->file('inti/module/App/asset/css/style.css', 'body{}');
        $this->b->file('inti/module/App/asset/img/favicon.ico', 'ikon');
        $this->b->file('inti/rahasia.txt', 'jangan');
        $this->b->file('inti/composer.json', (string) json_encode([
            'name'  => 'adems/dapur',
            'extra' => ['dapur' => ['asset' => [
                'adems/dapur'  => 'module/App/asset',
                'dapur/keluar' => '../',
                'dapur/kosong' => '',
                'dapur/hilang' => 'asset/tidak-ada',
            ]]],
        ]));
    }

    protected function tearDown(): void
    {
        $this->b->hapus();
    }

    public function testDibacaDariComposerJsonInti(): void
    {
        $daftar = (new AsetComposer(null, null, $this->inti))->daftar();

        self::assertSame(['adems/dapur'], array_keys(array_filter($daftar, static fn (array $a): bool => $a['versi'] === null)));
        self::assertSame(realpath($this->inti . '/module/App/asset'), $daftar['adems/dapur']['folder']);
        self::assertSame('adems/dapur', $daftar['adems/dapur']['paket']);

        $aset = $this->katalog()->cari('adems/dapur');
        self::assertNotNull($aset);
        self::assertFalse($aset->berversi);
        self::assertTrue($aset->terkunci());
        self::assertFalse($aset->isiDapatDiubah());
        self::assertSame(Aset::PUBLIK, $aset->akses);
    }

    public function testVendorIntiTetapDitolakUntukAsetLain(): void
    {
        self::assertFalse(Nama::sahAset('dapur/inti'));
        self::assertTrue(Nama::sahAset('dapur/inti', true));

        $this->b->config = ['aset' => ['dapur/lain' => ['folder' => $this->inti . '/module/App/asset']]];
        self::assertNull($this->katalog()->cari('dapur/lain'));
    }

    public function testHelperMemberiWaktuUbahDanHandlerMenyimpanLama(): void
    {
        $helper = new AsetHelper(fn (): Katalog => $this->katalog());
        $alamat = $helper('adems/dapur', 'css/style.css', '/asset/dapur/css/style.css');
        self::assertMatchesRegularExpression('#^/asset/adems/dapur/css/style\.css\?v=[0-9a-f]+$#', $alamat);

        parse_str((string) parse_url($alamat, PHP_URL_QUERY), $q);
        $r = $this->minta('adems/dapur/css/style.css', $q);
        self::assertSame('body{}', (string) $r->getBody());
        self::assertStringContainsString('immutable', $r->getHeaderLine('Cache-Control'));

        $r = $this->minta('adems/dapur/css/style.css', ['v' => 'salah']);
        self::assertSame('public, max-age=300', $r->getHeaderLine('Cache-Control'));
        self::assertSame('public, max-age=300', $this->minta('adems/dapur/css/style.css')->getHeaderLine('Cache-Control'));
    }

    public function testAlamatLamaDilayani(): void
    {
        self::assertSame('body{}', (string) $this->minta('dapur/css/style.css')->getBody());
        self::assertSame('ikon', (string) $this->minta('adems/img/favicon.ico')->getBody());
        self::assertSame('ikon', (string) $this->minta('dapur/img/favicon.ico')->getBody());
        self::assertSame(404, $this->minta('adems/dapur/../../rahasia.txt')->getStatusCode());
        self::assertSame(404, $this->minta('adems/img/lain.ico')->getStatusCode());
    }

    private function katalog(): Katalog
    {
        return new Katalog($this->b->pengaturan(), new AsetComposer(null, null, $this->inti), $this->b->daftar);
    }

    /** @param array<string, string> $query */
    private function minta(string $jalur, array $query = []): ResponseInterface
    {
        $request = (new ServerRequest([], [], '/asset/' . $jalur, 'GET'))->withAttribute('jalur', $jalur)->withQueryParams($query);

        return (new AsetHandler($this->katalog()))->handle($request);
    }
}
