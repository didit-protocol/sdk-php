<?php

declare(strict_types=1);

namespace Didit\Core;

use Psr\Http\Message\StreamInterface;

/**
 * A binary response (PDF report, CSV export, image), never parsed as JSON.
 */
final class BinaryContent
{
    public function __construct(
        private readonly RawResponse $rawResponse,
    ) {
    }

    /**
     * @internal
     */
    public static function fromResponse(RawResponse $response): self
    {
        return new self($response);
    }

    /**
     * The bytes.
     */
    public function contents(): string
    {
        return $this->rawResponse->body();
    }

    /**
     * The body as a PSR-7 stream, rewound to the start.
     */
    public function stream(): StreamInterface
    {
        $stream = $this->rawResponse->psrResponse->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $stream;
    }

    /**
     * The `Content-Type` header, for example `application/pdf`.
     */
    public function contentType(): ?string
    {
        return $this->rawResponse->header('content-type');
    }

    /**
     * The file name from `Content-Disposition`, when the API sends one.
     */
    public function filename(): ?string
    {
        $disposition = $this->rawResponse->header('content-disposition');
        if (null === $disposition) {
            return null;
        }
        if (1 === preg_match("/filename\\*=UTF-8''([^;]+)/i", $disposition, $match)) {
            return basename(rawurldecode(trim($match[1])));
        }
        if (1 === preg_match('/filename="?([^";]+)"?/i', $disposition, $match)) {
            return basename(trim($match[1]));
        }

        return null;
    }

    /**
     * Writes the bytes to a file.
     */
    public function saveTo(string $path): void
    {
        if (false === file_put_contents($path, $this->contents())) {
            throw new \RuntimeException(\sprintf('Could not write "%s".', $path));
        }
    }

    public function rawResponse(): RawResponse
    {
        return $this->rawResponse;
    }
}
