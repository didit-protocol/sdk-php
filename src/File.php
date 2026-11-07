<?php

declare(strict_types=1);

namespace Didit;

use Psr\Http\Message\StreamInterface;

/**
 * A file to upload in a multipart request: from a path, from bytes or from a
 * stream, with an optional file name and content type.
 *
 * ```php
 * $didit->idVerification->verify([
 *     'front_image' => File::fromPath('/tmp/front.jpg'),
 *     'back_image' => File::fromBytes($bytes, 'back.png'),
 * ]);
 * ```
 *
 * Upload fields also accept a PSR-7 stream or a PHP stream resource directly.
 */
final class File
{
    private const MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'bmp' => 'image/bmp',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'csv' => 'text/csv',
        'json' => 'application/json',
        'txt' => 'text/plain',
    ];

    /**
     * @param string|StreamInterface|resource|null $source
     */
    private function __construct(
        private readonly ?string $path,
        private readonly mixed $source,
        public readonly string $filename,
        public readonly string $contentType,
    ) {
    }

    /**
     * A file on disk, read when the request is sent.
     */
    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(\sprintf('File "%s" does not exist or is not readable.', $path));
        }
        $filename ??= basename($path);

        return new self($path, null, $filename, $contentType ?? self::guessContentType($filename));
    }

    /**
     * File contents held in memory.
     */
    public static function fromBytes(string $bytes, string $filename = 'file', ?string $contentType = null): self
    {
        return new self(null, $bytes, $filename, $contentType ?? self::guessContentType($filename));
    }

    /**
     * A PSR-7 stream or a PHP stream resource (for example from `fopen()`).
     *
     * @param StreamInterface|resource $stream
     */
    public static function fromStream(mixed $stream, ?string $filename = null, ?string $contentType = null): self
    {
        if (!$stream instanceof StreamInterface && !\is_resource($stream)) {
            throw new \InvalidArgumentException('Expected a PSR-7 stream or a stream resource.');
        }
        $filename ??= self::streamFilename($stream);

        return new self(null, $stream, $filename, $contentType ?? self::guessContentType($filename));
    }

    /**
     * @internal the file contents
     */
    public function contents(): string
    {
        if (null !== $this->path) {
            $contents = file_get_contents($this->path);
            if (false === $contents) {
                throw new \RuntimeException(\sprintf('Could not read file "%s".', $this->path));
            }

            return $contents;
        }
        $source = $this->source;
        if (\is_string($source)) {
            return $source;
        }
        if ($source instanceof StreamInterface) {
            if ($source->isSeekable()) {
                $source->rewind();
            }

            return $source->getContents();
        }
        if (\is_resource($source)) {
            $meta = stream_get_meta_data($source);
            if ($meta['seekable']) {
                rewind($source);
            }
            $contents = stream_get_contents($source);
            if (false === $contents) {
                throw new \RuntimeException('Could not read the stream.');
            }

            return $contents;
        }

        throw new \RuntimeException('The file has no readable source.');
    }

    private static function guessContentType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));

        return self::MIME_TYPES[$extension] ?? 'application/octet-stream';
    }

    /**
     * @param StreamInterface|resource $stream
     */
    private static function streamFilename(mixed $stream): string
    {
        $uri = $stream instanceof StreamInterface ? $stream->getMetadata('uri') : stream_get_meta_data($stream)['uri'] ?? null;
        if (\is_string($uri) && '' !== $uri && !str_starts_with($uri, 'php://')) {
            return basename($uri);
        }

        return 'file';
    }
}
