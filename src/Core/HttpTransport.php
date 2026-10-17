<?php

declare(strict_types=1);

namespace Didit\Core;

use Didit\Exception\ApiConnectionException;
use Didit\Exception\ApiTimeoutException;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends one PSR-7 request with a timeout and turns transport failures into
 * {@see ApiConnectionException} or {@see ApiTimeoutException}.
 *
 * PSR-18 has no timeout setting, so the timeout is applied natively for
 * Guzzle and Symfony HttpClient. With any other PSR-18 client, configure the
 * timeout on that client.
 *
 * The SDK never follows redirects, so credentials never reach another origin.
 *
 * @internal
 */
final class HttpTransport
{
    private readonly ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? self::defaultClient();
    }

    public function client(): ClientInterface
    {
        return $this->client;
    }

    /**
     * @throws ApiConnectionException
     */
    public function send(RequestInterface $request, float $timeout): ResponseInterface
    {
        try {
            $client = $this->client;
            if ($client instanceof \GuzzleHttp\ClientInterface) {
                return $client->send($request, [
                    'timeout' => $timeout,
                    'connect_timeout' => $timeout,
                    'http_errors' => false,
                    'allow_redirects' => false,
                ]);
            }
            if ($client instanceof \Symfony\Component\HttpClient\Psr18Client) {
                $client = $client->withOptions(['timeout' => $timeout, 'max_duration' => $timeout, 'max_redirects' => 0]);
            }

            return $client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw self::connectionException($e);
        }
    }

    /**
     * The client used when none is passed: Guzzle when installed, otherwise
     * Symfony HttpClient, otherwise any PSR-18 client php-http/discovery finds.
     */
    public static function defaultClient(): ClientInterface
    {
        if (class_exists(\GuzzleHttp\Client::class)) {
            return new \GuzzleHttp\Client(['http_errors' => false, 'allow_redirects' => false]);
        }
        if (class_exists(\Symfony\Component\HttpClient\Psr18Client::class) && class_exists(\Symfony\Component\HttpClient\HttpClient::class)) {
            return new \Symfony\Component\HttpClient\Psr18Client(\Symfony\Component\HttpClient\HttpClient::create(['max_redirects' => 0]));
        }

        return Psr18ClientDiscovery::find();
    }

    private static function connectionException(\Throwable $e): ApiConnectionException
    {
        [$isTimeout, $beforeSend] = self::classify($e);
        $message = self::sanitize($e->getMessage());
        if ($isTimeout) {
            return new ApiTimeoutException('Request timed out: ' . $message, !$beforeSend, $e);
        }

        return new ApiConnectionException('Connection error: ' . $message, !$beforeSend, $e);
    }

    /**
     * @return array{bool, bool} [is a timeout, failed before the request was sent]
     */
    private static function classify(\Throwable $e): array
    {
        $message = $e->getMessage();
        $errno = null;
        if ($e instanceof \GuzzleHttp\Exception\ConnectException || $e instanceof \GuzzleHttp\Exception\RequestException) {
            $context = $e->getHandlerContext();
            $errno = isset($context['errno']) && \is_int($context['errno']) ? $context['errno'] : null;
            if (isset($context['error']) && \is_string($context['error']) && '' !== $context['error']) {
                $message = $context['error'];
            }
        }

        $connectPhase = 1 === preg_match('/(could ?n.t resolve|could not resolve|resolving timed out|failed to connect|connection refused|connection timed out after|network is unreachable|no route to host|getaddrinfo|name or service not known|ssl connect error)/i', $message);
        if (null !== $errno) {
            // cURL: 6 resolve, 7 connect, 35 TLS handshake; 28 is ambiguous.
            $connectPhase = \in_array($errno, [6, 7, 35], true) || (28 === $errno && $connectPhase);
        }
        $isTimeout = 28 === $errno || 1 === preg_match('/timed? ?out|timeout/i', $message);

        return [$isTimeout, $connectPhase];
    }

    private static function sanitize(string $message): string
    {
        // Never echo URLs with query strings or credentials back to the caller.
        $message = (string) preg_replace('#https?://[^\s"\'<>()\[\]]+#i', '<url>', $message);

        return \strlen($message) > 300 ? substr($message, 0, 300) . '...' : $message;
    }
}
