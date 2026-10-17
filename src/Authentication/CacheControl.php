<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\RawResponse;

/**
 * How long a discovery document or JWKS may be cached, from its response's
 * `Cache-Control` and `Age` headers.
 *
 * @internal
 */
final class CacheControl
{
    /** Seconds to cache when the response sends no `max-age`. */
    public const DEFAULT_TTL = 300;

    /** Upper bound, so a revoked signing key never lingers for long. */
    public const MAX_TTL = 3600;

    private function __construct()
    {
    }

    /**
     * Seconds the response may be reused; 0 means do not cache it at all
     * (`no-store`, `no-cache` or `max-age=0`).
     */
    public static function ttl(RawResponse $response): int
    {
        $directives = [];
        foreach ($response->headers['cache-control'] ?? [] as $value) {
            foreach (explode(',', $value) as $part) {
                $pieces = explode('=', trim($part), 2);
                $name = strtolower(trim($pieces[0]));
                if ('' !== $name) {
                    $directives[$name] = isset($pieces[1]) ? trim($pieces[1], " \t\"") : null;
                }
            }
        }
        if (\array_key_exists('no-store', $directives) || \array_key_exists('no-cache', $directives)) {
            return 0;
        }

        $ttl = self::DEFAULT_TTL;
        if (\array_key_exists('max-age', $directives)) {
            $maxAge = $directives['max-age'];
            if (null === $maxAge || 1 !== preg_match('/^\d{1,10}$/D', $maxAge)) {
                // An invalid max-age makes the response stale (RFC 9111 4.2.1).
                return 0;
            }
            $ttl = (int) $maxAge;
        }

        $age = $response->header('age');
        if (null !== $age && 1 === preg_match('/^\d{1,10}$/D', trim($age))) {
            $ttl -= (int) trim($age);
        }

        return max(0, min($ttl, self::MAX_TTL));
    }
}
