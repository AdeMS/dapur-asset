<?php

declare(strict_types=1);

namespace AdeMS\Dapur\Asset;

use RuntimeException;

/** Kesalahan yang pesannya aman dan jelas untuk ditampilkan ke admin (mis. "File .php tidak diizinkan"). */
final class GagalAset extends RuntimeException
{
}
