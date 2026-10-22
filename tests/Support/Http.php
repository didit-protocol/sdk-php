<?php

declare(strict_types=1);

namespace Didit\Tests\Support;

use Psr\Http\Message\RequestInterface;

/**
 * Helpers to read what the SDK sent.
 */
final class Http
{
    /**
     * Query parameters in order, without PHP's `parse_str` name mangling.
     *
     * @return array<string, string>
     */
    public static function queryParams(RequestInterface $request): array
    {
        $query = $request->getUri()->getQuery();
        $out = [];
        if ('' === $query) {
            return $out;
        }
        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $out[rawurldecode($name)] = rawurldecode($value);
        }

        return $out;
    }

    public static function canonicalJson(string $body): string
    {
        $decoded = json_decode($body, false, 512, \JSON_THROW_ON_ERROR);

        return json_encode($decoded, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Multipart fields by name. Text parts give their value; file parts give
     * `file:<filename>:<content type>:<content>`.
     *
     * @return array<string, list<string>>
     */
    public static function multipartFields(RequestInterface $request): array
    {
        if (1 !== preg_match('/boundary=(.+)$/', $request->getHeaderLine('Content-Type'), $match)) {
            throw new \LogicException('Not a multipart request.');
        }
        $boundary = $match[1];
        $body = (string) $request->getBody();
        $out = [];
        foreach (explode('--' . $boundary, $body) as $part) {
            if ('' === trim($part) || str_starts_with($part, '--')) {
                continue;
            }
            [$rawHeaders, $content] = explode("\r\n\r\n", ltrim($part, "\r\n"), 2) + [1 => ''];
            $content = substr($content, 0, -2); // trailing CRLF
            if (1 !== preg_match('/name="([^"]*)"/', $rawHeaders, $name)) {
                continue;
            }
            if (1 === preg_match('/filename="([^"]*)"/', $rawHeaders, $filename)) {
                preg_match('/Content-Type: (\S+)/i', $rawHeaders, $type);
                $content = \sprintf('file:%s:%s:%s', $filename[1], $type[1] ?? '', $content);
            }
            $out[$name[1]][] = $content;
        }

        return $out;
    }
}
