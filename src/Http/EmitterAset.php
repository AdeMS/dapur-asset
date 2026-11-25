<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset\Http;

use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\Emitter\SapiStreamEmitter;
use Psr\Http\Message\ResponseInterface;

/**
 * Pengirim respons untuk file aset. Pengirim bawaan Mezzio mengubah seluruh isi respons menjadi teks sekaligus; untuk
 * file besar (lagu, video) itu menghabiskan memori. Pengirim ini hanya menangani respons berisi AliranFile dan
 * mengirimnya per 8 KB, termasuk sebagian file (Range).
 */
final class EmitterAset implements EmitterInterface
{
    public function emit(ResponseInterface $response): bool
    {
        if (! $response->getBody() instanceof AliranFile) {
            return false;
        }

        return (new SapiStreamEmitter(8192))->emit($response);
    }
}
