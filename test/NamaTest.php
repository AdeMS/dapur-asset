<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset\Test;

use AdeMS\Dapur\Asset\JenisFile;
use AdeMS\Dapur\Asset\Nama;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NamaTest extends TestCase
{
    public function testNamaAset(): void
    {
        self::assertTrue(Nama::sahAset('twbs/bootstrap'));
        self::assertTrue(Nama::sahAset('adems/uang'));
        self::assertTrue(Nama::sahAset('adems/bootstrap-icons'));
        self::assertFalse(Nama::sahAset('bootstrap'));
        self::assertFalse(Nama::sahAset('a/b/c'));
        self::assertFalse(Nama::sahAset('Adems/uang'));
        self::assertFalse(Nama::sahAset('dapur/apa'), 'vendor milik inti');
        self::assertFalse(Nama::sahAset('bootstrap/apa'), 'vendor milik inti');
        self::assertFalse(Nama::sahAset('../etc'));
        self::assertFalse(Nama::sahAset('.a/b'));
        self::assertSame('adems/uang', Nama::periksaAset(' Adems/Uang '));
    }

    public function testVersi(): void
    {
        self::assertSame('5.3.8', Nama::periksaVersi('v5.3.8'));
        self::assertTrue(Nama::sahVersi('1.0.0-beta.1'));
        self::assertFalse(Nama::sahVersi('../1'));
        self::assertFalse(Nama::sahVersi('.1'));
        self::assertSame(['5.10.0', '5.3.8', '5.3.2'], Nama::urutkanTerbaru(['5.3.2', '5.10.0', '5.3.8']));
    }

    public function testJalur(): void
    {
        self::assertSame('css/a.css', Nama::periksaJalur('/css/a.css/'));
        self::assertSame([], Nama::bagianJalur(''));

        foreach (['../a.css', 'a/../b.css', '.htaccess', 'css/.git/x', '@eaDir/a.jpg', "a\0b.css", 'a//b.css'] as $buruk) {
            try {
                Nama::bagianJalur($buruk);
                self::fail("Seharusnya ditolak: {$buruk}");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testJenisFile(): void
    {
        self::assertTrue(JenisFile::diizinkan('bootstrap.min.css'));
        self::assertTrue(JenisFile::diizinkan('FOTO.JPG'));
        self::assertFalse(JenisFile::diizinkan('index.php'));
        self::assertFalse(JenisFile::diizinkan('a.phtml'));
        self::assertFalse(JenisFile::diizinkan('a.phar'));
        self::assertFalse(JenisFile::diizinkan('a.html'));
        self::assertFalse(JenisFile::diizinkan('a.css.php'));
        self::assertSame('image/svg+xml', JenisFile::contentType('a.svg'));
    }
}
