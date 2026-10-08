<?php

declare(strict_types=1);

namespace Adems\Asset\Handler;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\Stream;

class AssetHandler implements RequestHandlerInterface
{
    public function __construct(private string $assetDirectory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (! str_starts_with($path, '/asset/')) {
            return $this->notFound();
        }

        $relativePath = rawurldecode(substr($path, strlen('/asset/')));
        $segments = explode('/', $relativePath);
        if (count($segments) < 2 || in_array('', $segments, true)) {
            return $this->notFound();
        }

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || str_contains($segment, '\\') || str_contains($segment, "\0")) {
                return $this->notFound();
            }
        }

        $assetRoot = realpath($this->assetDirectory);
        if ($assetRoot === false) {
            return $this->notFound();
        }

        $assetPath = realpath($assetRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments));
        if (
            $assetPath === false
            || ! is_file($assetPath)
            || ! is_readable($assetPath)
            || ! $this->isWithinAssetRoot($assetPath, $assetRoot)
        ) {
            return $this->notFound();
        }

        $contentType = mime_content_type($assetPath) ?: 'application/octet-stream';

        return new Response(new Stream($assetPath, 'r'), 200, ['Content-Type' => $contentType]);
    }

    private function isWithinAssetRoot(string $assetPath, string $assetRoot): bool
    {
        $rootPrefix = rtrim($assetRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $comparison = DIRECTORY_SEPARATOR === '\\' ? 'strncasecmp' : 'strncmp';

        return $comparison($assetPath, $rootPrefix, strlen($rootPrefix)) === 0;
    }

    private function notFound(): ResponseInterface
    {
        return new TextResponse('Not Found', 404);
    }
}
