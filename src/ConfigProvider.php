<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use AdeMS\Dapur\Asset\Handler\AsetHandler;
use AdeMS\Dapur\Asset\Http\EmitterDelegator;
use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Mezzio\Application;

/**
 * Modul aset: route `/asset/<vendor>/<aplikasi>/[<versi>/]<file>`, view helper `aset()`, dan tabel aset untuk
 * `php dapur pasang`.
 *
 * Cantumkan di config/modul.local.php inti agar berlaku untuk inti dan semua website. Pengaturannya
 * (`'asset' => [...]`, lihat kelas Pengaturan) sengaja tidak diberi nilai di sini.
 */
final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dapur'        => [
                'skema' => [AsetSkema::class],
            ],
            'dependencies' => [
                'factories'  => array_fill_keys([
                    Pengaturan::class,
                    NamaTabel::class,
                    DaftarAset::class,
                    Katalog::class,
                    Unggahan::class,
                    AsetHandler::class,
                    AsetHelper::class,
                ], PabrikLayanan::class),
                'delegators' => [
                    Application::class      => [RuteDelegator::class],
                    EmitterInterface::class => [EmitterDelegator::class],
                ],
            ],
            'view_helpers' => [
                'aliases'   => ['aset' => AsetHelper::class],
                'factories' => [AsetHelper::class => PabrikLayanan::class],
            ],
        ];
    }
}
