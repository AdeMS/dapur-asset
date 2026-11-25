<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use AdeMS\Dapur\Asset\Handler\AsetHandler;
use Mezzio\Application;
use Psr\Container\ContainerInterface;
use WeakMap;

/**
 * Mendaftarkan route `/asset/...`. Aman bila ConfigProvider aset dimuat lebih dari sekali (mis. di inti dan lewat
 * dashboard): route hanya didaftarkan sekali.
 */
final class RuteDelegator
{
    public const RUTE = 'dapur.asset';

    /** @var WeakMap<Application, true>|null */
    private static ?WeakMap $sudah = null;

    public function __invoke(ContainerInterface $container, string $name, callable $callback): Application
    {
        $app = $callback();
        assert($app instanceof Application);

        self::$sudah ??= new WeakMap();
        if (! isset(self::$sudah[$app])) {
            self::$sudah[$app] = true;
            $app->get('/asset/{jalur:.+}', AsetHandler::class, self::RUTE);
        }

        return $app;
    }
}
