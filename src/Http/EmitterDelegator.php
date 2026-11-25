<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset\Http;

use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\Emitter\EmitterStack;
use Psr\Container\ContainerInterface;

/** Menambahkan EmitterAset ke tumpukan pengirim respons Mezzio (dicoba lebih dulu daripada pengirim bawaan). */
final class EmitterDelegator
{
    public function __invoke(ContainerInterface $container, string $name, callable $callback): EmitterInterface
    {
        $emitter = $callback();
        if ($emitter instanceof EmitterStack) {
            foreach ($emitter as $e) {
                if ($e instanceof EmitterAset) {
                    return $emitter;
                }
            }
            $emitter->push(new EmitterAset());

            return $emitter;
        }

        $stack = new EmitterStack();
        $stack->push($emitter);
        $stack->push(new EmitterAset());

        return $stack;
    }
}
