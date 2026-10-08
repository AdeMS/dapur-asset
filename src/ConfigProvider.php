<?php

declare(strict_types=1);

namespace Adems\Asset;

class ConfigProvider
{
    /**
     * Returns the configuration array
     *
     * To add a bit of a structure, each section is defined in a separate
     * method which returns an array with its configuration.
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
            'templates'    => $this->getTemplates(),
            'routes'       => $this->getRoutes(),
        ];
    }

    /**
     * Returns the container dependencies
     */
    public function getDependencies(): array
    {
        return [
            'invokables' => [

            ],
            'factories'  => [
                Handler\AssetHandler::class => Handler\AssetHandlerFactory::class,
            ],
        ];
    }

    /**
     * Returns the templates configuration
     */
    public function getTemplates(): array
    {
        return [
            'paths' => [
                'asset'    => [__DIR__ . '/../templates/asset'],
            ],
        ];
    }

    public function getRoutes(): array
    {
        return [
            [
                'name' => 'asset',
                'path' => '/asset/:vendor/:filepath',
                'middleware' => Handler\AssetHandler::class,
                'allowed_methods' => ['GET'],
                'options' => [
                    'constraints' => [
                        'filepath' => '.+',
                    ],
                ],
            ]
        ];
    }
}