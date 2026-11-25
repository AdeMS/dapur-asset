<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset\Test;

use AdeMS\Dapur\Asset\Aset;
use AdeMS\Dapur\Asset\Handler\AsetHandler;
use AdeMS\Dapur\Asset\Http\AliranFile;
use AdeMS\Dapur\Asset\PenjagaAkses;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AsetHandlerTest extends TestCase
{
    private Bantu $b;

    protected function setUp(): void
    {
        $this->b = new Bantu();
        $k       = $this->b->kelola();
        $k->tambah('adems/uang', true, 'publik');
        foreach (['1.0.0', '1.1.0'] as $v) {
            $k->tambahVersi('adems/uang', $v);
            $k->simpanUnggahan('adems/uang', $v, 'css', 'uang.css', $this->b->file('tmp/' . $v, "versi {$v}"), false);
            $k->simpanUnggahan('adems/uang', $v, 'img', 'ikon.svg', $this->b->file('tmp/svg', '<svg/>'), false);
            $k->terbitkan('adems/uang', $v);
        }
        $k->tambahVersi('adems/uang', '2.0.0');
        $k->simpanUnggahan('adems/uang', '2.0.0', 'css', 'uang.css', $this->b->file('tmp/2', 'draf'), false);

        $k->tambah('adems/rahasia', false, 'login');
        $k->simpanUnggahan('adems/rahasia', null, '', 'a.txt', $this->b->file('tmp/r', 'rahasia'), false);
    }

    protected function tearDown(): void
    {
        $this->b->hapus();
    }

    public function testDenganVersiDisimpanLama(): void
    {
        $r = $this->minta('adems/uang/1.0.0/css/uang.css');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('versi 1.0.0', (string) $r->getBody());
        self::assertInstanceOf(AliranFile::class, $r->getBody());
        self::assertSame('text/css; charset=utf-8', $r->getHeaderLine('Content-Type'));
        self::assertStringContainsString('immutable', $r->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $r->getHeaderLine('X-Content-Type-Options'));
    }

    public function testTanpaVersiMemakaiVersiTerpilihDanDisimpanSebentar(): void
    {
        $r = $this->minta('adems/uang/css/uang.css');
        self::assertSame('versi 1.1.0', (string) $r->getBody());
        self::assertSame('public, max-age=300', $r->getHeaderLine('Cache-Control'));
    }

    public function testVersiBelumTerbitTidakDilayani(): void
    {
        self::assertSame(404, $this->minta('adems/uang/2.0.0/css/uang.css')->getStatusCode());
        self::assertSame(404, $this->minta('adems/tidak/ada.css')->getStatusCode());
        self::assertSame(404, $this->minta('adems/uang/1.0.0/../1.1.0/css/uang.css')->getStatusCode());
        self::assertSame(404, $this->minta('adems')->getStatusCode());
    }

    public function testSvgDiberiPembatas(): void
    {
        $r = $this->minta('adems/uang/1.0.0/img/ikon.svg');
        self::assertSame('image/svg+xml', $r->getHeaderLine('Content-Type'));
        self::assertStringContainsString('sandbox', $r->getHeaderLine('Content-Security-Policy'));
    }

    public function testTidakBerubahDanRentang(): void
    {
        $r    = $this->minta('adems/uang/1.0.0/css/uang.css');
        $etag = $r->getHeaderLine('ETag');
        self::assertSame(304, $this->minta('adems/uang/1.0.0/css/uang.css', ['If-None-Match' => $etag])->getStatusCode());

        $r = $this->minta('adems/uang/1.0.0/css/uang.css', ['Range' => 'bytes=0-4']);
        self::assertSame(206, $r->getStatusCode());
        self::assertSame('bytes 0-4/11', $r->getHeaderLine('Content-Range'));
        self::assertSame('5', $r->getHeaderLine('Content-Length'));

        $r = $this->minta('adems/uang/1.0.0/css/uang.css', ['Range' => 'bytes=-3']);
        self::assertSame('bytes 8-10/11', $r->getHeaderLine('Content-Range'));

        self::assertSame(416, $this->minta('adems/uang/1.0.0/css/uang.css', ['Range' => 'bytes=50-'])->getStatusCode());
    }

    public function testAksesLogin(): void
    {
        self::assertSame(404, $this->minta('adems/rahasia/a.txt')->getStatusCode(), 'tanpa penjaga: tidak pernah dilayani');

        $tolak = new class implements PenjagaAkses {
            public function boleh(ServerRequestInterface $request, Aset $aset): bool
            {
                return $request->getHeaderLine('X-Uji') === 'masuk';
            }
        };

        self::assertSame(404, $this->minta('adems/rahasia/a.txt', [], $tolak)->getStatusCode());
        $r = $this->minta('adems/rahasia/a.txt', ['X-Uji' => 'masuk'], $tolak);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringStartsWith('private', $r->getHeaderLine('Cache-Control'));
    }

    public function testAlamatLamaBootstrap(): void
    {
        foreach (['bootstrap/dist/css', 'icons/font/fonts'] as $f) {
            mkdir($this->b->folder . '/vendor/' . $f, 0777, true);
        }
        file_put_contents($this->b->folder . '/vendor/bootstrap/dist/css/bootstrap.min.css', 'bs');
        file_put_contents($this->b->folder . '/vendor/icons/font/fonts/bootstrap-icons.woff2', 'ikon');
        $this->b->composer = [
            'twbs/bootstrap'       => ['paket' => 'twbs/bootstrap', 'folder' => $this->b->folder . '/vendor/bootstrap/dist', 'versi' => '5.3.8'],
            'twbs/bootstrap-icons' => ['paket' => 'twbs/bootstrap-icons', 'folder' => $this->b->folder . '/vendor/icons/font', 'versi' => '1.13.1'],
        ];

        $r = $this->minta('bootstrap/css/bootstrap.min.css');
        self::assertSame('bs', (string) $r->getBody());
        self::assertSame('public, max-age=300', $r->getHeaderLine('Cache-Control'));
        self::assertSame('ikon', (string) $this->minta('bootstrap/font/fonts/bootstrap-icons.woff2')->getBody());
    }

    public function testAsetNonaktif(): void
    {
        $this->b->kelola()->ubah('adems/rahasia', 'publik', false, '');
        self::assertSame(404, $this->minta('adems/rahasia/a.txt')->getStatusCode());
    }

    /** @param array<string, string> $header */
    private function minta(string $jalur, array $header = [], ?PenjagaAkses $penjaga = null): ResponseInterface
    {
        $request = (new ServerRequest([], [], '/asset/' . $jalur, 'GET', 'php://memory', $header))->withAttribute('jalur', $jalur);

        return (new AsetHandler($this->b->katalog(), $penjaga))->handle($request);
    }
}
