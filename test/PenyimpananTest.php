<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset\Test;

use AdeMS\Dapur\Asset\GagalAset;
use AdeMS\Dapur\Asset\Penyimpanan;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class PenyimpananTest extends TestCase
{
    private Bantu $b;
    private Penyimpanan $s;
    private string $akar;

    protected function setUp(): void
    {
        $this->b    = new Bantu();
        $this->akar = $this->b->folder . '/data/asset/adems/uang';
        $this->s    = new Penyimpanan($this->akar, 1024 * 1024, 10);
        $this->s->siapkan();
    }

    protected function tearDown(): void
    {
        $this->b->hapus();
    }

    public function testSimpanDaftarGantiNamaHapus(): void
    {
        $sumber = $this->b->file('tmp/a', 'body{}');
        self::assertFalse($this->s->simpanFile($sumber, 'css/uang.css'));
        self::assertTrue($this->s->simpanFile($sumber, 'css/uang.css'), 'menggantikan file lama');
        $this->s->buatFolder('img');

        $isi = $this->s->daftar();
        self::assertSame(['css', 'img'], array_column($isi, 'nama'));
        self::assertTrue($isi[0]['folder']);
        self::assertSame('css/uang.css', $this->s->daftar('css')[0]['jalur']);
        self::assertSame(0660, fileperms($this->akar . '/css/uang.css') & 0777);
        self::assertSame(02770, fileperms($this->akar . '/css') & 07777);

        self::assertSame('css/utama.css', $this->s->gantiNama('css/uang.css', 'utama.css'));
        self::assertNotNull($this->s->fileUntukDilayani('css/utama.css'));
        self::assertSame(['jumlah' => 1, 'ukuran' => 6], $this->s->ringkasan());

        $this->s->hapus('css');
        self::assertSame(['img'], array_column($this->s->daftar(), 'nama'));
    }

    public function testTolakFileBerbahaya(): void
    {
        $sumber = $this->b->file('tmp/a', '<?php echo 1;');
        foreach (['index.php', '.htaccess', '../luar.css', 'a/../../b.css', 'x.phtml'] as $nama) {
            try {
                $this->s->simpanFile($sumber, $nama);
                self::fail("Seharusnya ditolak: {$nama}");
            } catch (GagalAset) {
                self::assertFileDoesNotExist($this->akar . '/' . $nama);
            }
        }

        $this->s->simpanFile($this->b->file('tmp/b', 'x'), 'a.css');
        $this->expectException(GagalAset::class);
        $this->s->gantiNama('a.css', 'a.php');
    }

    public function testTerlaluBesar(): void
    {
        $s = new Penyimpanan($this->akar, 3);
        $this->expectException(GagalAset::class);
        $s->simpanFile($this->b->file('tmp/a', 'empat'), 'a.css');
    }

    public function testFileUntukDilayaniTidakKeluarFolder(): void
    {
        $this->b->file('data/asset/rahasia.css', 'rahasia');
        symlink($this->b->folder . '/data/asset', $this->akar . '/tautan');
        self::assertNull($this->s->fileUntukDilayani('tautan/rahasia.css'));
        self::assertNull($this->s->fileUntukDilayani('../rahasia.css'));
        self::assertNull($this->s->fileUntukDilayani(''));

        file_put_contents($this->akar . '/x.php', '<?php');
        self::assertNull($this->s->fileUntukDilayani('x.php'), 'jenis tidak diizinkan tidak pernah dilayani');
    }

    public function testFolderTidakDibuatLewatSymlinkKeluar(): void
    {
        mkdir($this->b->folder . '/luar');
        symlink($this->b->folder . '/luar', $this->akar . '/tautan');
        try {
            $this->s->simpanFile($this->b->file('tmp/a', 'x'), 'tautan/baru/a.css');
            self::fail('Seharusnya ditolak');
        } catch (GagalAset) {
            self::assertDirectoryDoesNotExist($this->b->folder . '/luar/baru');
        }
    }

    public function testBongkarZipMelewatiFolderTeratas(): void
    {
        $zip = $this->zip([
            'bootstrap-5.3.8-dist/css/bootstrap.min.css' => 'a',
            'bootstrap-5.3.8-dist/js/bootstrap.min.js'   => 'b',
            '__MACOSX/._x'                               => 'abaikan',
        ]);

        self::assertSame(2, $this->s->bongkarZip($zip));
        self::assertFileExists($this->akar . '/css/bootstrap.min.css');
        self::assertFileExists($this->akar . '/js/bootstrap.min.js');

        self::assertSame(1, $this->s->bongkarZip($this->zip(['logo.png' => 'p']), 'img'));
        self::assertFileExists($this->akar . '/img/logo.png');
    }

    public function testZipBerisiFileTerlarangTidakDibongkarSamaSekali(): void
    {
        $zip = $this->zip(['a.css' => 'a', 'shell.php' => '<?php']);
        try {
            $this->s->bongkarZip($zip);
            self::fail('Seharusnya ditolak');
        } catch (GagalAset $e) {
            self::assertStringContainsString('shell.php', $e->getMessage());
            self::assertFileDoesNotExist($this->akar . '/a.css');
        }

        $this->expectException(GagalAset::class);
        $this->s->bongkarZip($this->zip(['../luar.css' => 'x']));
    }

    public function testZipTerlaluBanyakFile(): void
    {
        $isi = [];
        for ($i = 0; $i < 11; $i++) {
            $isi["f{$i}.css"] = 'x';
        }

        $this->expectException(GagalAset::class);
        $this->s->bongkarZip($this->zip($isi));
    }

    /** @param array<string, string> $isi */
    private function zip(array $isi): string
    {
        $file = $this->b->folder . '/tmp/' . bin2hex(random_bytes(4)) . '.zip';
        @mkdir(dirname($file), 0777, true);
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE);
        foreach ($isi as $nama => $data) {
            $zip->addFromString($nama, $data);
        }
        $zip->close();

        return $file;
    }
}
