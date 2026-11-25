<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset\Http;

use Laminas\Diactoros\Stream;

/** Isi respons berupa file aset; dikirim sedikit demi sedikit oleh EmitterAset agar file besar tidak dimuat ke memori. */
final class AliranFile extends Stream
{
}
