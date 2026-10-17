<?php

declare(strict_types=1);

namespace Didit\Tests\Authentication\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from registered routes and records every
 * request. The last answer queued for a route repeats.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var array<string, list<ResponseInterface|\Closure(RequestInterface): ResponseInterface>> */
    private array $routes = [];

    /**
     * @param ResponseInterface|\Closure(RequestInterface): ResponseInterface $response
     */
    public function on(string $method, string $url, ResponseInterface|\Closure $response): self
    {
        $this->routes[$method . ' ' . $url][] = $response;

        return $this;
    }

    /**
     * Replaces every answer queued for a route.
     *
     * @param ResponseInterface|\Closure(RequestInterface): ResponseInterface $response
     */
    public function replace(string $method, string $url, ResponseInterface|\Closure $response): self
    {
        $this->routes[$method . ' ' . $url] = [$response];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $key = $request->getMethod() . ' ' . $request->getUri();
        $queue = $this->routes[$key] ?? [];
        if ([] === $queue) {
            throw new \LogicException('Unexpected request: ' . $key);
        }
        $answer = \count($queue) > 1 ? array_shift($queue) : $queue[0];
        $this->routes[$key] = $queue;

        return $answer instanceof \Closure ? $answer($request) : $answer;
    }

    public function count(string $method, string $url): int
    {
        $count = 0;
        foreach ($this->requests as $request) {
            if ($request->getMethod() === $method && (string) $request->getUri() === $url) {
                ++$count;
            }
        }

        return $count;
    }

    public function last(): RequestInterface
    {
        $last = end($this->requests);
        if (false === $last) {
            throw new \LogicException('No request was sent.');
        }

        return $last;
    }
}
