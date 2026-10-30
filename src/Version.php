<?php

declare(strict_types=1);

namespace Didit;

/**
 * The SDK version, sent in the `User-Agent` and `X-Didit-Client` headers.
 */
final class Version
{
    public const VERSION = '1.0.1'; // x-release-please-version

    private function __construct()
    {
    }
}
