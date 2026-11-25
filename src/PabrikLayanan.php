<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use AdeMS\Dapur\Asset\Handler\AsetHandler;
use AdeMS\Dapur\Database\PabrikPemasang;
use Laminas\Db\Adapter\Adapter;
use Psr\Container\ContainerInterface;
use RuntimeException;

/** Factory untuk semua layanan modul aset. */
final class PabrikLayanan
{
    public function __invoke(ContainerInterface $c, string $nama): object
    {
        return match ($nama) {
            Pengaturan::class => new Pengaturan(self::config($c)['asset'] ?? []),
            NamaTabel::class  => new NamaTabel(PabrikPemasang::awalan(self::config($c))),
            DaftarAset::class => new DaftarAset(self::db($c), $c->get(NamaTabel::class)),
            Katalog::class    => self::katalog($c),
            Unggahan::class   => new Unggahan($c->get(Pengaturan::class)->folderSementara, $c->get(Pengaturan::class)->unggahMaks),
            AsetHandler::class => new AsetHandler(
                $c->get(Katalog::class),
                $c->has(PenjagaAkses::class) ? $c->get(PenjagaAkses::class) : null,
            ),
            AsetHelper::class => new AsetHelper(static fn (): Katalog => $c->get(Katalog::class)),
            default => throw new RuntimeException("Layanan aset tidak dikenal: {$nama}"),
        };
    }

    public static function katalog(ContainerInterface $c, ?string $lingkup = null): Katalog
    {
        return new Katalog(
            $c->get(Pengaturan::class),
            new AsetComposer(),
            $c->has(Adapter::class) ? $c->get(DaftarAset::class) : null,
            $lingkup ?? Lingkup::sekarang(),
        );
    }

    /** @return array<string, mixed> */
    private static function config(ContainerInterface $c): array
    {
        $config = $c->has('config') ? $c->get('config') : [];

        return is_array($config) ? $config : [];
    }

    private static function db(ContainerInterface $c): Adapter
    {
        $db = $c->get(Adapter::class);
        assert($db instanceof Adapter);

        return $db;
    }
}
