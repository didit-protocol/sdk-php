<?php

declare(strict_types=1);

namespace Didit\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that records requests and answers from a queue. No network.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|\Throwable|\Closure(RequestInterface): ResponseInterface> */
    private array $queue = [];

    /**
     * @param array<string, string> $headers
     */
    public function queue(int $status, string $body = '', array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers, $body);

        return $this;
    }

    /**
     * @param array<string, string> $headers
     * @param array<mixed>|null $data
     */
    public function queueJson(int $status, ?array $data, array $headers = []): self
    {
        return $this->queue($status, json_encode($data, \JSON_THROW_ON_ERROR), $headers + ['Content-Type' => 'application/json']);
    }

    public function queueException(\Throwable $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    /**
     * @param \Closure(RequestInterface): ResponseInterface $handler
     */
    public function queueHandler(\Closure $handler): self
    {
        $this->queue[] = $handler;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if (null === $next) {
            throw new \LogicException(\sprintf('No response queued for %s %s.', $request->getMethod(), (string) $request->getUri()));
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if ($next instanceof \Closure) {
            return $next($request);
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        $last = end($this->requests);
        if (false === $last) {
            throw new \LogicException('No request was sent.');
        }

        return $last;
    }

    public function remaining(): int
    {
        return \count($this->queue);
    }
}
