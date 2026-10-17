<?php

declare(strict_types=1);

namespace Didit\Core;

use Didit\Exception\ConfigurationException;
use Didit\RequestOptions;

/**
 * The resolved, immutable configuration of a client.
 *
 * @internal
 */
final class ClientOptions
{
    public const DEFAULT_BASE_URL = 'https://verification.didit.me';
    public const DEFAULT_ACCOUNT_BASE_URL = 'https://apx.didit.me/auth/v2';
    public const DEFAULT_TIMEOUT = 60.0;
    public const DEFAULT_MAX_RETRIES = 2;

    /**
     * @param array<string, string> $defaultHeaders
     */
    public function __construct(
        #[\SensitiveParameter]
        public readonly ?string $apiKey,
        public readonly string $baseUrl,
        public readonly string $accountBaseUrl,
        public readonly ?string $organizationId,
        public readonly ?string $applicationId,
        #[\SensitiveParameter]
        public readonly ?string $accountToken,
        public readonly float $timeout,
        public readonly int $maxRetries,
        public readonly array $defaultHeaders,
    ) {
        if ($timeout <= 0) {
            throw new ConfigurationException('timeout must be greater than 0 seconds.');
        }
        if ($maxRetries < 0) {
            throw new ConfigurationException('maxRetries must be 0 or more.');
        }
    }

    /**
     * Applies the documented defaults and environment fallbacks.
     *
     * @param array<string, string> $defaultHeaders
     */
    public static function resolve(
        #[\SensitiveParameter]
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $organizationId = null,
        ?string $applicationId = null,
        ?float $timeout = null,
        ?int $maxRetries = null,
        array $defaultHeaders = [],
        ?string $accountBaseUrl = null,
        #[\SensitiveParameter]
        ?string $accountToken = null,
    ): self {
        return new self(
            apiKey: self::nonEmpty($apiKey) ?? self::env('DIDIT_API_KEY'),
            baseUrl: self::normalizeBaseUrl($baseUrl ?? self::DEFAULT_BASE_URL),
            accountBaseUrl: self::normalizeBaseUrl($accountBaseUrl ?? self::DEFAULT_ACCOUNT_BASE_URL),
            organizationId: self::nonEmpty($organizationId) ?? self::env('DIDIT_ORGANIZATION_ID'),
            applicationId: self::nonEmpty($applicationId) ?? self::env('DIDIT_APPLICATION_ID'),
            accountToken: self::nonEmpty($accountToken),
            timeout: $timeout ?? self::DEFAULT_TIMEOUT,
            maxRetries: $maxRetries ?? self::DEFAULT_MAX_RETRIES,
            defaultHeaders: $defaultHeaders,
        );
    }

    /**
     * These options with the non-null values of a per-request override.
     */
    public function merge(?RequestOptions $options): self
    {
        if (null === $options) {
            return $this;
        }

        return new self(
            apiKey: self::nonEmpty($options->apiKey) ?? $this->apiKey,
            baseUrl: null === $options->baseUrl ? $this->baseUrl : self::normalizeBaseUrl($options->baseUrl),
            accountBaseUrl: null === $options->accountBaseUrl ? $this->accountBaseUrl : self::normalizeBaseUrl($options->accountBaseUrl),
            organizationId: self::nonEmpty($options->organizationId) ?? $this->organizationId,
            applicationId: self::nonEmpty($options->applicationId) ?? $this->applicationId,
            accountToken: self::nonEmpty($options->accountToken) ?? $this->accountToken,
            timeout: $options->timeout ?? $this->timeout,
            maxRetries: $options->maxRetries ?? $this->maxRetries,
            defaultHeaders: array_merge($this->defaultHeaders, $options->headers),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $info = get_object_vars($this);
        $info['apiKey'] = null === $this->apiKey ? null : '[redacted]';
        $info['accountToken'] = null === $this->accountToken ? null : '[redacted]';

        return $info;
    }

    public static function normalizeBaseUrl(string $url): string
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || !\in_array(strtolower($parts['scheme']), ['https', 'http'], true)) {
            throw new ConfigurationException(\sprintf('Invalid base URL "%s": expected an absolute http(s) URL.', $url));
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException('The base URL must not contain credentials, a query string or a fragment.');
        }

        return rtrim($url, '/');
    }

    private static function nonEmpty(?string $value): ?string
    {
        return null === $value || '' === $value ? null : $value;
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
