<?php

declare(strict_types=1);

namespace Adems\Asset\Handler;

use Psr\Container\ContainerInterface;

class AssetHandlerFactory
{
    public function __invoke(ContainerInterface $container) : AssetHandler
    {
        $root = getenv('DAPUR_ROOT');
        if ($root === false || $root === '') {
            $root = getcwd();
        }

        if ($root === false) {
            throw new \RuntimeException('Unable to determine the Dapur root directory.');
        }

        return new AssetHandler($root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'asset');
    }
}
