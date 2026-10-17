<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\HttpTransport;
use Didit\Core\Hydrate;
use Didit\Core\NoContent;
use Didit\Core\RawResponse;
use Didit\Exception\ApiConnectionException;
use Didit\Exception\ApiStatusException;
use Didit\Exception\ConfigurationException;
use Didit\Exception\DiditException;
use Didit\Version;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Didit Authentication for your backend: verify the access and ID tokens of
 * your application's end users, exchange and refresh tokens, check and end
 * sessions, and run step-up verification.
 *
 * Every application is its own OpenID Connect issuer
 * (`https://<name>.auth.didit.me` or your custom domain). This class talks
 * only to that issuer and never takes or sends your Didit API key.
 *
 * ```php
 * $auth = Authentication::fromPublishableKey('pk_live_...', cache: $psr16Cache);
 * $claims = $auth->verifyAccessToken($bearerToken, audience: $applicationId);
 * ```
 *
 * The discovery document and the JWKS are fetched on first use and cached as
 * their `Cache-Control` allows, in memory and in the optional PSR-16 cache.
 * Pass a PSR-16 cache when PHP processes are short-lived (PHP-FPM), so every
 * request does not fetch the keys again.
 */
final class Authentication
{
    /** Clock skew, in seconds, allowed for `exp`, `nbf` and `iat`. */
    public const LEEWAY = 60;

    /** An unknown `kid` fetches the JWKS again at most this often, in seconds. */
    public const JWKS_REFETCH_INTERVAL = 60;

    private const MAX_TOKEN_LENGTH = 16384;

    private const CACHE_FORMAT = 1;

    private const TYPE_ACCESS_TOKEN = 'at+jwt';

    private const TYPE_ID_TOKEN = 'jwt';

    /** The issuer every token's `iss` must equal, without a trailing slash. */
    public readonly string $issuer;

    private readonly string $issuerOrigin;

    private readonly string $cachePrefix;

    /** @var \Closure(): (int|float) */
    private readonly \Closure $clock;

    private ?HttpTransport $transport = null;

    /** @var array{expires_at: float, data: array<string, mixed>}|null */
    private ?array $discoveryEntry = null;

    /** @var array{expires_at: float, data: array<string, mixed>}|null */
    private ?array $jwksEntry = null;

    private ?float $lastJwksFetch = null;

    /**
     * @param string $issuer the application's issuer, for example `https://your-app.auth.didit.me`
     *                       or your active custom domain
     * @param ClientInterface|null $httpClient any PSR-18 client; Guzzle or Symfony HttpClient when omitted
     * @param CacheInterface|null $cache PSR-16 cache for the discovery document and the JWKS
     * @param float $timeout seconds allowed per request
     * @param (\Closure(): (int|float))|null $clock returns the current Unix time in seconds (for tests)
     *
     * @throws ConfigurationException when the issuer is not an https URL
     */
    public function __construct(
        string $issuer,
        private readonly ?ClientInterface $httpClient = null,
        private ?RequestFactoryInterface $requestFactory = null,
        private ?StreamFactoryInterface $streamFactory = null,
        private readonly ?CacheInterface $cache = null,
        private readonly float $timeout = 60.0,
        ?\Closure $clock = null,
    ) {
        $this->issuer = self::normalizeIssuer($issuer);
        $this->issuerOrigin = (string) self::origin($this->issuer);
        $this->cachePrefix = 'didit_auth.' . substr(hash('sha256', $this->issuer), 0, 32) . '.';
        $this->clock = $clock ?? static fn (): float => microtime(true);
        if ($timeout <= 0) {
            throw new ConfigurationException('The timeout must be a positive number of seconds.');
        }
    }

    /**
     * Builds the client from a publishable key: `pk_live_` (live) or
     * `pk_test_` (sandbox) followed by the unpadded base64url issuer.
     *
     * @param (\Closure(): (int|float))|null $clock
     *
     * @throws ConfigurationException when the key is not a valid publishable key
     */
    public static function fromPublishableKey(
        string $publishableKey,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?CacheInterface $cache = null,
        float $timeout = 60.0,
        ?\Closure $clock = null,
    ): self {
        return new self(self::issuerFromPublishableKey($publishableKey), $httpClient, $requestFactory, $streamFactory, $cache, $timeout, $clock);
    }

    /**
     * A PKCE verifier and its S256 challenge, from 32 random bytes.
     */
    public static function createPkce(): Pkce
    {
        return Pkce::fromVerifier(Base64Url::encode(random_bytes(32)));
    }

    /**
     * A random `state` for the sign-in redirect (43 base64url characters).
     * Check that the callback returns the same value before exchanging the code.
     */
    public static function createState(): string
    {
        return Base64Url::encode(random_bytes(32));
    }

    /**
     * A random `nonce` for the sign-in (43 base64url characters). Pass it to
     * {@see self::verifyIdToken()} with the ID token from the code exchange.
     */
    public static function createNonce(): string
    {
        return Base64Url::encode(random_bytes(32));
    }

    /**
     * The issuer's discovery document, cached as its `Cache-Control` allows.
     *
     * @throws ConfigurationException when the document names another issuer, or endpoints
     *                                outside the issuer's origin
     * @throws ApiStatusException on a non-2xx answer
     * @throws ApiConnectionException when no answer arrives
     */
    public function discovery(): OpenIdConfiguration
    {
        [$data, $response] = $this->loadDiscovery();

        return null === $response ? OpenIdConfiguration::fromArray($data) : OpenIdConfiguration::fromResponse($response);
    }

    /**
     * The issuer's public signing keys, cached as their `Cache-Control` allows.
     *
     * @throws ConfigurationException|ApiStatusException|ApiConnectionException
     */
    public function jwks(): JsonWebKeySet
    {
        [$data, $response] = $this->loadJwks(false);

        return null === $response ? JsonWebKeySet::fromArray($data) : JsonWebKeySet::fromResponse($response);
    }

    /**
     * Verifies an access token offline and returns its claims.
     *
     * Checks: ES256 signature with a key from the issuer's JWKS, header
     * `typ` `at+jwt`, `iss` equal to the issuer, `aud` when an audience is
     * given (pass your application ID), and `exp`, `nbf` and `iat` with
     * {@see self::LEEWAY} seconds of leeway. Offline verification sees a
     * revoked session only when its access token expires (within 5 minutes);
     * call {@see self::getSession()} before sensitive actions.
     *
     * @throws TokenVerificationException when the token is not valid
     * @throws ConfigurationException when ext-openssl is missing or discovery does not match the issuer
     * @throws ApiStatusException|ApiConnectionException when the keys cannot be fetched
     */
    public function verifyAccessToken(string $token, ?string $audience = null): AccessTokenClaims
    {
        return AccessTokenClaims::fromArray($this->verify($token, self::TYPE_ACCESS_TOKEN, $audience));
    }

    /**
     * Verifies an ID token offline and returns its claims.
     *
     * Same checks as {@see self::verifyAccessToken()} with header `typ`
     * `JWT`, plus `aud` (your application ID) and `nonce`. Pass the nonce
     * the sign-in started with for the ID token from {@see self::exchangeCode()}.
     * Pass null when the sign-in had no nonce, and for ID tokens from
     * {@see self::refresh()}, which never carry one; a null nonce rejects a
     * token that carries one.
     *
     * @throws TokenVerificationException when the token is not valid
     * @throws ConfigurationException|ApiStatusException|ApiConnectionException
     */
    public function verifyIdToken(string $token, ?string $nonce, string $audience): IdTokenClaims
    {
        $claims = $this->verify($token, self::TYPE_ID_TOKEN, $audience);
        $actual = $claims['nonce'] ?? null;
        if (null === $nonce) {
            if (\array_key_exists('nonce', $claims)) {
                throw new TokenVerificationException(TokenVerificationException::INVALID_NONCE, 'The ID token carries a nonce: pass the nonce the sign-in started with.');
            }
        } elseif (!\is_string($actual) || !hash_equals($nonce, $actual)) {
            throw new TokenVerificationException(TokenVerificationException::INVALID_NONCE, 'The ID token nonce does not match the expected nonce.');
        }

        return IdTokenClaims::fromArray($claims);
    }

    /**
     * Exchanges the authorization code from your `redirect_uri` for tokens
     * (`POST token/`, grant `authorization_code`). The code works once,
     * within 60 seconds. Check the callback's `state` first, and verify the
     * returned ID token with the sign-in's nonce.
     *
     * @param string $codeVerifier the PKCE verifier whose challenge started the sign-in
     * @param string $redirectUri the exact `redirect_uri` the sign-in started with
     *
     * @throws ApiStatusException on a non-2xx answer (`400 {"error": "invalid_grant"}` and others)
     * @throws ApiConnectionException|ConfigurationException
     */
    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): TokenSet
    {
        $response = $this->send('POST', $this->endpoint('token_endpoint'), form: [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri,
        ]);

        $this->jsonObject($response);

        return TokenSet::fromResponse($response);
    }

    /**
     * Swaps a refresh token for a new access, ID and refresh token
     * (`POST token/`, grant `refresh_token`). Replace all three together.
     *
     * Refresh tokens are single-use and sending a used one again revokes the
     * session, so serialise refreshes for one session (one at a time, for
     * example under a lock keyed by the session ID). After a refresh fails
     * with 400 or gets no answer, start sign-in again rather than retrying:
     * a retry after a refresh that did succeed is a reuse. A 429 did not run
     * and is safe to retry after `Retry-After`.
     *
     * @throws ApiStatusException on a non-2xx answer (`400 {"error": "invalid_grant"}` and others)
     * @throws ApiConnectionException|ConfigurationException
     */
    public function refresh(string $refreshToken): TokenSet
    {
        $response = $this->send('POST', $this->endpoint('token_endpoint'), form: [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
        $this->jsonObject($response);

        return TokenSet::fromResponse($response);
    }

    /**
     * Checks the access token against the live session (`GET session/`).
     * Unlike offline verification, it fails at once when the session was
     * revoked, idled out or replaced by a refresh: use it before sensitive
     * actions.
     *
     * @throws ApiStatusException a rejected token is `AuthenticationException` (401 `invalid_token`)
     * @throws ApiConnectionException
     */
    public function getSession(string $accessToken): Session
    {
        $response = $this->send('GET', $this->issuer . '/session/', accessToken: $accessToken);
        $this->jsonObject($response);

        return Session::fromResponse($response);
    }

    /**
     * Signs the person out (`DELETE session/`): the session's refresh token
     * stops working and its access tokens fail {@see self::getSession()} at
     * once. Tokens verified offline stay valid until they expire.
     *
     * @throws ApiStatusException|ApiConnectionException
     */
    public function revokeSession(string $accessToken): NoContent
    {
        return NoContent::fromResponse($this->send('DELETE', $this->issuer . '/session/', accessToken: $accessToken));
    }

    /**
     * Asks whether the person has to verify before a sensitive action
     * (`POST step-up/`).
     *
     * When no rule applies the result has `required` false: go ahead.
     * Otherwise send the person to `verificationUrl`; they come back to
     * `$returnTo` with `step_up=<id>&status=...`, which proves nothing. Read
     * the step-up with {@see self::retrieveStepUp()} and act only when its
     * status is `approved`.
     *
     * @param string $action `payout`, `bank` or `contact` (see the `StepUp::ACTION_*` constants)
     * @param string|null $amount required for `payout`: a decimal string with up to two decimals, such as `"900.00"`
     * @param string|null $currency ISO 4217 code of the amount; defaults to the payout rule's currency
     * @param string|null $returnTo where the person returns; must be one of the application's redirect URIs.
     *                              The issuer currently answers 400 without it, so pass it.
     *
     * @throws ApiStatusException|ApiConnectionException
     */
    public function createStepUp(string $accessToken, string $action, ?string $amount = null, ?string $currency = null, ?string $returnTo = null): StepUp
    {
        $body = ['action' => $action];
        if (null !== $amount) {
            $body['amount'] = $amount;
        }
        if (null !== $currency) {
            $body['currency'] = $currency;
        }
        if (null !== $returnTo) {
            $body['return_to'] = $returnTo;
        }

        $response = $this->send('POST', $this->issuer . '/step-up/', accessToken: $accessToken, json: $body);
        $this->jsonObject($response);

        return StepUp::fromResponse($response);
    }

    /**
     * Reads a step-up's current status (`GET step-up/{step_up_id}/`), with
     * an access token of the session that created it. Act only when its
     * status is `approved` ({@see StepUp::isApproved()}).
     *
     * @throws ApiStatusException an unknown step-up is `NotFoundException`
     * @throws ApiConnectionException
     */
    public function retrieveStepUp(string $accessToken, string $stepUpId): StepUp
    {
        if ('' === $stepUpId) {
            throw new \InvalidArgumentException('The step-up ID must not be empty.');
        }
        // HTTP clients collapse "." and ".." segments, which would read another endpoint.
        if ('.' === $stepUpId || '..' === $stepUpId) {
            throw new \InvalidArgumentException('The step-up ID must not be "." or "..".');
        }
        $response = $this->send('GET', $this->issuer . '/step-up/' . rawurlencode($stepUpId) . '/', accessToken: $accessToken);
        $this->jsonObject($response);

        return StepUp::fromResponse($response);
    }

    /**
     * @return array<string, mixed> the verified claims
     */
    private function verify(string $token, string $expectedType, ?string $audience): array
    {
        Es256::requireOpenssl();

        if (\strlen($token) > self::MAX_TOKEN_LENGTH || 2 !== substr_count($token, '.')) {
            throw new TokenVerificationException(TokenVerificationException::MALFORMED, 'The token is not a JWT: it must be three dot-separated base64url segments.');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = explode('.', $token);

        $header = self::decodeSegment($encodedHeader, 'header');
        $alg = $header['alg'] ?? null;
        if ('ES256' !== $alg) {
            throw new TokenVerificationException(TokenVerificationException::UNSUPPORTED_ALGORITHM, \sprintf('Only ES256 tokens are accepted; this token has alg %s.', self::describe($alg)));
        }
        $typ = $header['typ'] ?? null;
        if (!\is_string($typ) || !self::typeMatches($typ, $expectedType)) {
            throw new TokenVerificationException(TokenVerificationException::INVALID_TYPE, \sprintf(
                'Expected an %s (typ "%s"); this token has typ %s.',
                self::TYPE_ACCESS_TOKEN === $expectedType ? 'access token' : 'ID token',
                self::TYPE_ACCESS_TOKEN === $expectedType ? 'at+jwt' : 'JWT',
                self::describe($typ),
            ));
        }
        if (\array_key_exists('crit', $header)) {
            throw new TokenVerificationException(TokenVerificationException::MALFORMED, 'The token has critical header parameters ("crit") that this verifier does not support.');
        }
        $kid = $header['kid'] ?? null;
        if (!\is_string($kid) || '' === $kid) {
            throw new TokenVerificationException(TokenVerificationException::UNKNOWN_KEY, 'The token header names no signing key ("kid").');
        }

        $claims = self::decodeSegment($encodedPayload, 'payload');
        $signature = Base64Url::decode($encodedSignature);
        if (null === $signature || '' === $signature) {
            throw new TokenVerificationException(TokenVerificationException::MALFORMED, 'The token signature is not strict base64url.');
        }

        $key = $this->findKey($kid);
        if (!Es256::verify($encodedHeader . '.' . $encodedPayload, $signature, $key)) {
            throw new TokenVerificationException(TokenVerificationException::INVALID_SIGNATURE, 'The token signature is not valid.');
        }

        $this->checkClaims($claims, $audience);

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function checkClaims(array $claims, ?string $audience): void
    {
        $iss = $claims['iss'] ?? null;
        if (!\is_string($iss) || $iss !== $this->issuer) {
            throw new TokenVerificationException(TokenVerificationException::INVALID_ISSUER, \sprintf('The token issuer %s is not the configured issuer "%s".', self::describe($iss), $this->issuer));
        }

        if (null !== $audience) {
            $aud = $claims['aud'] ?? null;
            $matches = \is_string($aud) ? $aud === $audience : \is_array($aud) && \in_array($audience, $aud, true);
            if (!$matches) {
                throw new TokenVerificationException(TokenVerificationException::INVALID_AUDIENCE, 'The token audience does not include the expected audience.');
            }
        }

        $now = $this->now();
        $exp = self::numericDate($claims, 'exp', true);
        if ($now >= $exp + self::LEEWAY) {
            throw new TokenVerificationException(TokenVerificationException::EXPIRED, 'The token has expired.');
        }
        $iat = self::numericDate($claims, 'iat', true);
        if ($iat > $now + self::LEEWAY) {
            throw new TokenVerificationException(TokenVerificationException::ISSUED_IN_FUTURE, 'The token was issued in the future.');
        }
        $nbf = self::numericDate($claims, 'nbf', false);
        if (null !== $nbf && $nbf > $now + self::LEEWAY) {
            throw new TokenVerificationException(TokenVerificationException::NOT_YET_VALID, 'The token is not valid yet.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return ($required is true ? float : float|null)
     */
    private static function numericDate(array $claims, string $name, bool $required): ?float
    {
        if (!\array_key_exists($name, $claims)) {
            if ($required) {
                throw new TokenVerificationException(TokenVerificationException::INVALID_CLAIM, \sprintf('The token has no "%s" claim.', $name));
            }

            return null;
        }
        $value = $claims[$name];
        if ((!\is_int($value) && !\is_float($value)) || !is_finite((float) $value)) {
            throw new TokenVerificationException(TokenVerificationException::INVALID_CLAIM, \sprintf('The token\'s "%s" claim is not a number.', $name));
        }

        return (float) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeSegment(string $segment, string $name): array
    {
        $json = Base64Url::decode($segment);
        if (null === $json || !str_starts_with(ltrim($json), '{')) {
            throw new TokenVerificationException(TokenVerificationException::MALFORMED, \sprintf('The token %s is not a base64url-encoded JSON object.', $name));
        }
        try {
            $value = json_decode($json, true, 64, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new TokenVerificationException(TokenVerificationException::MALFORMED, \sprintf('The token %s is not valid JSON.', $name), $e);
        }
        $data = Hydrate::assoc($value);
        if (null === $data) {
            throw new TokenVerificationException(TokenVerificationException::MALFORMED, \sprintf('The token %s is not a JSON object.', $name));
        }

        return $data;
    }

    private static function typeMatches(string $typ, string $expected): bool
    {
        // Media types are case-insensitive and may carry the "application/" prefix (RFC 7515 4.1.9).
        $typ = strtolower($typ);
        if (str_starts_with($typ, 'application/')) {
            $typ = substr($typ, \strlen('application/'));
        }

        return $typ === $expected;
    }

    /**
     * The signing key with this `kid`, fetching the JWKS again (at most once
     * per {@see self::JWKS_REFETCH_INTERVAL}) when the cached set lacks it.
     */
    private function findKey(string $kid): \OpenSSLAsymmetricKey
    {
        [$jwks, $response] = $this->loadJwks(false);
        $key = self::keyFromSet($jwks, $kid);
        if (null === $key && null === $response && $this->mayRefetchJwks()) {
            [$jwks] = $this->loadJwks(true);
            $key = self::keyFromSet($jwks, $kid);
        }
        if (null === $key) {
            throw new TokenVerificationException(TokenVerificationException::UNKNOWN_KEY, 'No EC P-256 signing key in the issuer\'s JWKS matches the token\'s "kid".');
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $jwks
     */
    private static function keyFromSet(array $jwks, string $kid): ?\OpenSSLAsymmetricKey
    {
        $keys = $jwks['keys'] ?? null;
        if (!\is_array($keys)) {
            return null;
        }
        foreach ($keys as $jwk) {
            if (\is_array($jwk) && ($jwk['kid'] ?? null) === $kid) {
                $key = Es256::publicKeyFromJwk($jwk);
                if (null !== $key) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function mayRefetchJwks(): bool
    {
        $last = $this->lastJwksFetch;
        $shared = $this->cacheGet('jwks_fetched_at');
        if (\is_int($shared) || \is_float($shared)) {
            $last = max($last ?? (float) $shared, (float) $shared);
        }

        return null === $last || $this->now() - $last >= self::JWKS_REFETCH_INTERVAL;
    }

    /**
     * @return array{array<string, mixed>, RawResponse|null} the document, and the response when it was fetched now
     */
    private function loadDiscovery(): array
    {
        $entry = $this->fresh($this->discoveryEntry) ?? $this->cachedEntry('discovery');
        if (null !== $entry && null === $this->discoveryProblem($entry['data'])) {
            $this->discoveryEntry = $entry;

            return [$entry['data'], null];
        }

        $response = $this->send('GET', $this->issuer . '/.well-known/openid-configuration');
        $data = $this->jsonObject($response);
        $problem = $this->discoveryProblem($data);
        if (null !== $problem) {
            throw new ConfigurationException($problem);
        }
        $this->discoveryEntry = $this->remember('discovery', $data, CacheControl::ttl($response));

        return [$data, $response];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function discoveryProblem(array $data): ?string
    {
        $issuer = $data['issuer'] ?? null;
        if ($issuer !== $this->issuer) {
            return \sprintf(
                'The discovery document of "%s" names the issuer %s. Configure the issuer exactly as its discovery document names it (no trailing slash; a custom domain replaces the default host once active).',
                $this->issuer,
                self::describe($issuer),
            );
        }
        foreach (['token_endpoint', 'jwks_uri'] as $field) {
            $url = $data[$field] ?? null;
            if (!\is_string($url) || self::origin($url) !== $this->issuerOrigin) {
                return \sprintf('The discovery document of "%s" has a missing or foreign "%s" (%s); only endpoints on the issuer\'s own origin are used.', $this->issuer, $field, self::describe($url));
            }
        }

        return null;
    }

    /**
     * A discovery endpoint, already checked to be on the issuer's origin.
     */
    private function endpoint(string $field): string
    {
        [$data] = $this->loadDiscovery();
        $url = $data[$field] ?? null;

        return \is_string($url) ? $url : throw new ConfigurationException(\sprintf('The discovery document has no "%s".', $field));
    }

    /**
     * @return array{array<string, mixed>, RawResponse|null} the key set, and the response when it was fetched now
     */
    private function loadJwks(bool $refetch): array
    {
        if (!$refetch) {
            $entry = $this->fresh($this->jwksEntry) ?? $this->cachedEntry('jwks');
            if (null !== $entry && self::isKeySet($entry['data'])) {
                $this->jwksEntry = $entry;

                return [$entry['data'], null];
            }
        }

        $url = $this->endpoint('jwks_uri');
        // Count the attempt before sending, so a failing endpoint is not hammered either.
        $now = $this->now();
        $this->lastJwksFetch = $now;
        $this->cacheSet('jwks_fetched_at', $now, self::JWKS_REFETCH_INTERVAL);

        $response = $this->send('GET', $url);
        $data = $this->jsonObject($response);
        if (!self::isKeySet($data)) {
            throw new DiditException(\sprintf('The JWKS of "%s" has no "keys" array.', $this->issuer));
        }
        $this->jwksEntry = $this->remember('jwks', $data, CacheControl::ttl($response));

        return [$data, $response];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function isKeySet(array $data): bool
    {
        return isset($data['keys']) && \is_array($data['keys']) && array_is_list($data['keys']);
    }

    /**
     * @param array{expires_at: float, data: array<string, mixed>}|null $entry
     *
     * @return array{expires_at: float, data: array<string, mixed>}|null
     */
    private function fresh(?array $entry): ?array
    {
        return null !== $entry && $entry['expires_at'] > $this->now() ? $entry : null;
    }

    /**
     * Stores a fetched document in the PSR-16 cache and returns the in-memory
     * entry, or null when the response may not be cached.
     *
     * @param array<string, mixed> $data
     *
     * @return array{expires_at: float, data: array<string, mixed>}|null
     */
    private function remember(string $name, array $data, int $ttl): ?array
    {
        if ($ttl <= 0) {
            return null;
        }
        $entry = ['expires_at' => $this->now() + $ttl, 'data' => $data];
        $this->cacheSet($name, ['format' => self::CACHE_FORMAT] + $entry, $ttl);

        return $entry;
    }

    /**
     * @return array{expires_at: float, data: array<string, mixed>}|null
     */
    private function cachedEntry(string $name): ?array
    {
        $value = $this->cacheGet($name);
        if (!\is_array($value) || self::CACHE_FORMAT !== ($value['format'] ?? null)) {
            return null;
        }
        $expiresAt = $value['expires_at'] ?? null;
        $data = Hydrate::assoc($value['data'] ?? null);
        if ((!\is_float($expiresAt) && !\is_int($expiresAt)) || null === $data) {
            return null;
        }

        return $this->fresh(['expires_at' => (float) $expiresAt, 'data' => $data]);
    }

    private function cacheGet(string $name): mixed
    {
        if (null === $this->cache) {
            return null;
        }
        try {
            return $this->cache->get($this->cachePrefix . $name);
        } catch (\Exception) {
            // A cache outage must not break verification: fetch instead.
            return null;
        }
    }

    private function cacheSet(string $name, mixed $value, int $ttl): void
    {
        if (null === $this->cache) {
            return;
        }
        try {
            $this->cache->set($this->cachePrefix . $name, $value, $ttl);
        } catch (\Exception) {
            // Best effort: the in-memory copy still serves this instance.
        }
    }

    /**
     * @param array<string, string>|null $form form-encoded body
     * @param array<string, string>|null $json JSON body
     */
    private function send(string $method, string $url, ?string $accessToken = null, ?array $form = null, ?array $json = null): RawResponse
    {
        $request = $this->requestFactory()->createRequest($method, $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', \sprintf('didit-php/%s (PHP %s; %s)', Version::VERSION, \PHP_VERSION, \PHP_OS_FAMILY))
            ->withHeader('X-Didit-Client', 'php/' . Version::VERSION);
        if (null !== $accessToken) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $accessToken);
        }
        if (null !== $form) {
            $request = $request
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody($this->streamFactory()->createStream(http_build_query($form, '', '&')));
        } elseif (null !== $json) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory()->createStream(json_encode($json, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)));
        }

        $this->transport ??= new HttpTransport($this->httpClient);
        $response = new RawResponse($this->transport->send($request, $this->timeout), $method, (string) parse_url($url, \PHP_URL_PATH));
        if ($response->status < 200 || $response->status > 299) {
            // Tokens and codes never reach an exception message, even when echoed back.
            $redact = array_values(array_filter([$accessToken, $form['refresh_token'] ?? null, $form['code'] ?? null, $form['code_verifier'] ?? null], 'is_string'));
            throw ApiStatusException::create($response, $redact);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonObject(RawResponse $response): array
    {
        $data = Hydrate::assoc($response->parsedBody());
        if (null === $data) {
            throw new DiditException(\sprintf(
                '%s %s answered HTTP %d without a JSON object%s.',
                $response->method,
                $response->path,
                $response->status,
                null === $response->requestId ? '' : \sprintf(' (request id %s)', $response->requestId),
            ));
        }

        return $data;
    }

    private function requestFactory(): RequestFactoryInterface
    {
        return $this->requestFactory ??= Psr17FactoryDiscovery::findRequestFactory();
    }

    private function streamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory ??= Psr17FactoryDiscovery::findStreamFactory();
    }

    private function now(): float
    {
        return (float) ($this->clock)();
    }

    private static function issuerFromPublishableKey(string $publishableKey): string
    {
        $key = trim($publishableKey);
        foreach (['pk_live_', 'pk_test_'] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                $issuer = Base64Url::decode(substr($key, \strlen($prefix)));
                if (null === $issuer || 1 !== preg_match('/^[\x21-\x7e]+$/D', $issuer)) {
                    throw new ConfigurationException(\sprintf('The publishable key does not carry a base64url issuer after "%s".', $prefix));
                }
                try {
                    return self::normalizeIssuer($issuer);
                } catch (ConfigurationException $e) {
                    throw new ConfigurationException('The publishable key does not carry a valid issuer: ' . $e->getMessage(), 0, $e);
                }
            }
        }

        throw new ConfigurationException('A publishable key starts with "pk_live_" or "pk_test_" followed by the base64url issuer; find it in the Didit console.');
    }

    private static function normalizeIssuer(string $issuer): string
    {
        $issuer = trim($issuer);
        if (str_starts_with($issuer, 'pk_live_') || str_starts_with($issuer, 'pk_test_')) {
            throw new ConfigurationException('That is a publishable key, not an issuer URL: use Authentication::fromPublishableKey().');
        }
        $parts = 1 === preg_match('/^[\x21-\x7e]+$/D', $issuer) ? parse_url($issuer) : false;
        $scheme = strtolower(\is_array($parts) ? $parts['scheme'] ?? '' : '');
        $host = strtolower(\is_array($parts) ? $parts['host'] ?? '' : '');
        $secure = 'https' === $scheme || ('http' === $scheme && \in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
        if (!\is_array($parts) || '' === $host || !$secure || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException(\sprintf('The issuer must be an https URL such as "https://your-app.auth.didit.me", with no query or fragment; got %s.', self::describe($issuer)));
        }

        return rtrim($issuer, '/');
    }

    /**
     * `scheme://host:port` in lower case, or null for anything that is not an
     * absolute http(s) URL without credentials.
     */
    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ('https' !== $scheme && 'http' !== $scheme) {
            return null;
        }

        return $scheme . '://' . strtolower($parts['host']) . ':' . ($parts['port'] ?? ('https' === $scheme ? 443 : 80));
    }

    /**
     * A short, safe rendering of an untrusted value for error messages.
     */
    private static function describe(mixed $value): string
    {
        if (null === $value) {
            return '(none)';
        }
        if (!\is_string($value)) {
            return '(' . get_debug_type($value) . ')';
        }
        $encoded = json_encode(\strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);

        return false === $encoded ? '(string)' : $encoded;
    }
}
