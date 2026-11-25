<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset\Test;

use AdeMS\Dapur\Asset\GagalAset;
use AdeMS\Dapur\Asset\Unggahan;
use PHPUnit\Framework\TestCase;

final class UnggahanTest extends TestCase
{
    private Bantu $b;
    private Unggahan $u;

    protected function setUp(): void
    {
        $this->b = new Bantu();
        $this->u = new Unggahan($this->b->folder . '/data/cache/asset-unggah', 100);
    }

    protected function tearDown(): void
    {
        $this->b->hapus();
    }

    public function testPotonganBerurutan(): void
    {
        $kode = $this->u->mulai('a.css', 10, 7, ['aset' => 'adems/uang']);
        self::assertSame(6, $this->u->potongan($kode, 7, 0, 'body{}'));
        self::assertSame(10, $this->u->potongan($kode, 7, 1, 'a{x}'));

        $hasil = $this->u->selesai($kode, 7);
        self::assertSame('body{}a{x}', file_get_contents($hasil['file']));
        self::assertSame('a.css', $hasil['nama']);
        self::assertSame(['aset' => 'adems/uang'], $hasil['data']);

        $this->u->buang($kode);
        self::assertDirectoryDoesNotExist($this->b->folder . '/data/cache/asset-unggah/' . $kode);
    }

    public function testDitolak(): void
    {
        $kode = $this->u->mulai('a.css', 10, 7);

        foreach ([
            fn () => $this->u->potongan($kode, 8, 0, 'x'),               // user lain
            fn () => $this->u->potongan($kode, 7, 1, 'x'),               // urutan salah
            fn () => $this->u->potongan($kode, 7, 0, str_repeat('x', 11)), // melebihi ukuran
            fn () => $this->u->selesai($kode, 7),                        // belum lengkap
            fn () => $this->u->mulai('b.css', 101, 7),                   // terlalu besar
            fn () => $this->u->potongan('../../etc', 7, 0, 'x'),         // kode tidak sah
        ] as $i => $coba) {
            try {
                $coba();
                self::fail("Seharusnya ditolak (#{$i})");
            } catch (GagalAset) {
                self::assertTrue(true);
            }
        }
    }
}
