<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset\Test;

use AdeMS\Dapur\Asset\AsetComposer;
use AdeMS\Dapur\Asset\AsetHelper;
use AdeMS\Dapur\Asset\Http\EmitterAset;
use AdeMS\Dapur\Asset\Http\EmitterDelegator;
use Laminas\HttpHandlerRunner\Emitter\EmitterStack;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class AsetHelperTest extends TestCase
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

    public function testAlamatMenyebutVersi(): void
    {
        $k = $this->b->kelola();
        $k->tambah('adems/uang', true, 'publik');
        $k->tambahVersi('adems/uang', '1.0.0');
        $k->simpanUnggahan('adems/uang', '1.0.0', 'css', 'uang.css', $this->b->file('tmp/a', 'a'), false);

        $helper = new AsetHelper(fn () => $this->b->katalog());
        self::assertNull($helper->alamat('adems/uang', 'css/uang.css'), 'belum ada versi terbit');
        self::assertSame('/cadangan.css', $helper('adems/uang', 'css/uang.css', '/cadangan.css'));

        $k->terbitkan('adems/uang', '1.0.0');
        $helper = new AsetHelper(fn () => $this->b->katalog());
        self::assertSame('/asset/adems/uang/1.0.0/css/uang.css', $helper('adems/uang', 'css/uang.css'));
        self::assertSame('1.0.0', $helper->versi('adems/uang'));
        self::assertSame('/asset/tidak/ada/x.css', $helper('tidak/ada', 'x.css'));

        $k->tambah('adems/logo', false, 'publik');
        $k->simpanUnggahan('adems/logo', null, '', 'logo kecil.png', $this->b->file('tmp/l', 'png'), false);
        $helper = new AsetHelper(fn () => $this->b->katalog());
        self::assertMatchesRegularExpression('#^/asset/adems/logo/logo%20kecil\.png\?v=[0-9a-f]+$#', $helper('adems/logo', 'logo kecil.png'));
    }

    public function testVersiComposerLewatPhp(): void
    {
        mkdir($this->b->folder . '/vendor/twbs/bootstrap/dist/css', 0777, true);
        file_put_contents($this->b->folder . '/vendor/twbs/bootstrap/dist/css/bootstrap.min.css', 'x');
        $this->b->composer = ['twbs/bootstrap' => ['paket' => 'twbs/bootstrap', 'folder' => $this->b->folder . '/vendor/twbs/bootstrap/dist', 'versi' => '5.3.8']];

        $helper = new AsetHelper(fn () => $this->b->katalog());
        self::assertSame('/asset/twbs/bootstrap/5.3.8/css/bootstrap.min.css', $helper('twbs/bootstrap', 'css/bootstrap.min.css', '/asset/bootstrap/css/bootstrap.min.css'));

        $k = $this->b->kelola();
        $k->tambahVersi('twbs/bootstrap', '5.4.0');
        $k->simpanUnggahan('twbs/bootstrap', '5.4.0', 'css', 'bootstrap.min.css', $this->b->file('tmp/b', 'y'), false);
        $k->terbitkan('twbs/bootstrap', '5.4.0');
        $k->pilihVersi('twbs/bootstrap', 'toko.contoh.test', '5.4.0');

        $helper = new AsetHelper(fn () => $this->b->katalog('toko.contoh.test'));
        self::assertSame('/asset/twbs/bootstrap/5.4.0/css/bootstrap.min.css', $helper('twbs/bootstrap', 'css/bootstrap.min.css', '/asset/bootstrap/css/bootstrap.min.css'));
    }

    public function testExtraComposer(): void
    {
        $file = $this->b->file('vendor/composer/installed.json', json_encode(['packages' => [
            ['name' => 'phpunit/phpunit', 'extra' => ['dapur' => ['asset' => ['adems/uji' => 'src']]]],
            ['name' => 'tidak/terpasang', 'extra' => ['dapur' => ['asset' => ['adems/x' => 'public']]]],
        ]]));

        $daftar = (new AsetComposer($file))->daftar();
        self::assertArrayHasKey('adems/uji', $daftar);
        self::assertSame('phpunit/phpunit', $daftar['adems/uji']['paket']);
        self::assertArrayNotHasKey('adems/x', $daftar);
    }

    public function testEmitterDitambahkanSekali(): void
    {
        $c     = $this->createStub(ContainerInterface::class);
        $stack = (new EmitterDelegator())($c, 'emitter', static function (): EmitterStack {
            $s = new EmitterStack();
            $s->push(new SapiEmitter());

            return $s;
        });
        $stack = (new EmitterDelegator())($c, 'emitter', static fn () => $stack);

        self::assertCount(2, $stack);
        self::assertInstanceOf(EmitterAset::class, $stack->top());
    }
}
