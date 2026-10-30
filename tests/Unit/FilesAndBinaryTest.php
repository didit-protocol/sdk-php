<?php

declare(strict_types=1);

namespace Didit\Tests\Unit;

use Didit\Core\Multipart;
use Didit\Exception\NotFoundException;
use Didit\File;
use Didit\Tests\Support\Http;
use Didit\Tests\Support\TestClient;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Multipart uploads, binary responses and 204 results (DESIGN section 8).
 *
 * @internal
 */
final class FilesAndBinaryTest extends TestCase
{
    private const OK = ['request_id' => 'r1', 'status' => 'Approved'];

    private TestClient $support;

    private string $dir;

    protected function setUp(): void
    {
        $this->support = new TestClient();
        $this->dir = sys_get_temp_dir() . '/didit-files-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dir));
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir . '/*');
        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testUploadFromAPath(): void
    {
        $path = $this->tempFile('front.png', "\x89PNG\r\n\x1a\nfront-bytes");
        $this->support->http->queueJson(200, self::OK);

        $this->support->client()->idVerification->verify([
            'front_image' => File::fromPath($path),
            'back_image' => File::fromPath($path, 'back-side.jpg', 'image/jpeg'),
        ]);

        $request = $this->support->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v3/id-verification/', $request->getUri()->getPath());
        self::assertSame([
            'front_image' => ["file:front.png:image/png:\x89PNG\r\n\x1a\nfront-bytes"],
            'back_image' => ["file:back-side.jpg:image/jpeg:\x89PNG\r\n\x1a\nfront-bytes"],
        ], Http::multipartFields($request));
    }

    public function testFileFromPathIsReadWhenTheRequestIsSent(): void
    {
        $path = $this->tempFile('doc.pdf', 'old');
        $file = File::fromPath($path);
        file_put_contents($path, 'new');
        $this->support->http->queueJson(200, self::OK);

        $this->support->client()->documentAi->analyze(['document' => $file, 'fields' => 'name']);

        self::assertSame(['file:doc.pdf:application/pdf:new'], Http::multipartFields($this->support->http->lastRequest())['document']);
    }

    public function testMissingPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        File::fromPath($this->dir . '/missing.jpg');
    }

    public function testUploadFromBytes(): void
    {
        $bytes = implode('', array_map('chr', range(0, 255)));
        $this->support->http->queueJson(200, self::OK);

        $this->support->client()->faceMatch->compare([
            'user_image' => File::fromBytes($bytes, 'selfie.JPG'),
            'ref_image' => File::fromBytes('ref', 'ref.bin', 'image/webp'),
        ]);

        $fields = Http::multipartFields($this->support->http->lastRequest());
        self::assertSame(['file:selfie.JPG:image/jpeg:' . $bytes], $fields['user_image'], 'every byte value survives');
        self::assertSame(['file:ref.bin:image/webp:ref'], $fields['ref_image']);
    }

    public function testBytesWithoutNameOrTypeUseDefaults(): void
    {
        $file = File::fromBytes('abc');

        self::assertSame('file', $file->filename);
        self::assertSame('application/octet-stream', $file->contentType);
    }

    public function testUploadFromAPsr7Stream(): void
    {
        $stream = Utils::streamFor('stream-bytes');
        $stream->read(3); // already partly read: the SDK rewinds
        $named = Utils::streamFor('named-stream');
        $this->support->http->queueJson(200, self::OK);

        $this->support->client()->faceMatch->compare([
            'user_image' => $stream,
            'ref_image' => File::fromStream($named, 'ref.png'),
        ]);

        $fields = Http::multipartFields($this->support->http->lastRequest());
        self::assertSame(['file:file:application/octet-stream:stream-bytes'], $fields['user_image']);
        self::assertSame(['file:ref.png:image/png:named-stream'], $fields['ref_image']);
    }

    public function testUploadFromAPhpStreamResource(): void
    {
        $path = $this->tempFile('portrait.webp', 'resource-bytes');
        $handle = fopen($path, 'r');
        self::assertIsResource($handle);
        $memory = fopen('php://memory', 'w+');
        self::assertIsResource($memory);
        fwrite($memory, 'memory-bytes');
        $this->support->http->queueJson(200, self::OK);

        try {
            $this->support->client()->faceMatch->compare([
                'user_image' => $handle,
                'ref_image' => File::fromStream($memory, null, 'image/heic'),
            ]);
        } finally {
            fclose($handle);
            fclose($memory);
        }

        $fields = Http::multipartFields($this->support->http->lastRequest());
        self::assertSame(['file:portrait.webp:image/webp:resource-bytes'], $fields['user_image']);
        self::assertSame(['file:file:image/heic:memory-bytes'], $fields['ref_image']);
    }

    public function testFileFromStreamRejectsAClosedResource(): void
    {
        $handle = fopen('php://memory', 'r');
        self::assertIsResource($handle);
        fclose($handle);

        $this->expectException(\InvalidArgumentException::class);
        File::fromStream($handle);
    }

    public function testMultipartBoundaryAndPartLayout(): void
    {
        $this->support->http->queueJson(200, self::OK);
        $this->support->http->queueJson(200, self::OK);
        $client = $this->support->client();

        $client->faceMatch->compare(['user_image' => File::fromBytes('u', 'u.png'), 'ref_image' => File::fromBytes('r', 'r.png'), 'vendor_data' => 'user-1']);
        $client->faceMatch->compare(['user_image' => File::fromBytes('u', 'u.png'), 'ref_image' => File::fromBytes('r', 'r.png')]);

        [$first, $second] = $this->support->http->requests;
        $boundary = self::boundary($first);
        self::assertNotSame($boundary, self::boundary($second), 'a fresh boundary per request');
        self::assertSame(
            "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"user_image\"; filename=\"u.png\"\r\n"
            . "Content-Type: image/png\r\n\r\n"
            . "u\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"ref_image\"; filename=\"r.png\"\r\n"
            . "Content-Type: image/png\r\n\r\n"
            . "r\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"vendor_data\"\r\n\r\n"
            . "user-1\r\n"
            . "--{$boundary}--\r\n",
            (string) $first->getBody(),
        );
        self::assertSame(TestClient::API_KEY, $first->getHeaderLine('x-api-key'));
    }

    public function testFileNamesAreEscaped(): void
    {
        $this->support->http->queueJson(200, self::OK);

        $this->support->client()->faceMatch->compare([
            'user_image' => File::fromBytes('u', 'résumé.pdf'),
            'ref_image' => File::fromBytes('r', "evil\"\r\nX-Injected: 1.png"),
        ]);

        $body = (string) $this->support->http->lastRequest()->getBody();
        self::assertStringContainsString("Content-Disposition: form-data; name=\"user_image\"; filename=\"résumé.pdf\"; filename*=UTF-8''r%C3%A9sum%C3%A9.pdf\r\nContent-Type: application/pdf\r\n", $body);
        self::assertStringContainsString('filename="evil%22%0D%0AX-Injected: 1.png"', $body);
        self::assertStringNotContainsString("\r\nX-Injected", $body);
    }

    public function testMultipartFieldEncodingThroughAnOperation(): void
    {
        $this->support->http->queueJson(200, self::OK);

        $this->support->client()->faceMatch->compare([
            'user_image' => File::fromBytes('u', 'u.png'),
            'ref_image' => File::fromBytes('r', 'r.png'),
            'face_match_score_decline_threshold' => 0.85,
            'rotate_image' => true,
            'save_api_request' => false,
            'metadata' => ['user' => ['id' => 42, 'tags' => ['a', 'b']], 'flow' => 'onboarding'],
        ]);

        $request = $this->support->http->lastRequest();
        self::assertSame([
            'user_image' => ['file:u.png:image/png:u'],
            'ref_image' => ['file:r.png:image/png:r'],
            'face_match_score_decline_threshold' => ['0.85'],
            'rotate_image' => ['true'],
            'save_api_request' => ['false'],
            'metadata' => ['{"user":{"id":42,"tags":["a","b"]},"flow":"onboarding"}'],
        ], Http::multipartFields($request), 'booleans as true/false, objects as one JSON text field');
        self::assertStringContainsString("Content-Disposition: form-data; name=\"metadata\"\r\n\r\n{", (string) $request->getBody(), 'text parts carry no content type');
    }

    public function testMultipartEncodingRules(): void
    {
        $body = Multipart::encode([
            'vendor_data' => null,
            'count' => 3,
            'ratio' => 1.0,
            'labels' => ['first', 'second', 3, true],
            'files' => [File::fromBytes('a', 'a.png'), File::fromBytes('b', 'b.png')],
            'objects' => [['k' => 1], ['k' => 2]],
            'metadata' => [],
            'captured_at' => new \DateTimeImmutable('2026-03-01T12:00:00+02:00'),
        ], 'B');

        self::assertSame(
            self::textPart('count', '3')
            . self::textPart('ratio', '1.0')
            . self::textPart('labels', 'first') . self::textPart('labels', 'second') . self::textPart('labels', '3') . self::textPart('labels', 'true')
            . "--B\r\nContent-Disposition: form-data; name=\"files\"; filename=\"a.png\"\r\nContent-Type: image/png\r\n\r\na\r\n"
            . "--B\r\nContent-Disposition: form-data; name=\"files\"; filename=\"b.png\"\r\nContent-Type: image/png\r\n\r\nb\r\n"
            . self::textPart('objects', '[{"k":1},{"k":2}]')
            . self::textPart('captured_at', '2026-03-01T12:00:00+02:00')
            . "--B--\r\n",
            $body,
            'null and empty lists are left out, lists of scalars and files repeat, lists of objects are JSON',
        );
        self::assertTrue(Multipart::containsFile(['files' => [File::fromBytes('a')]]), 'a file inside a list counts');
        self::assertFalse(Multipart::containsFile(['metadata' => ['nested' => File::fromBytes('a')]]), 'only top-level files and lists of files');
    }

    public function testEmptyObjectFieldIsSentAsAnEmptyJsonObject(): void
    {
        $this->support->http->queueJson(200, self::OK);

        $this->support->client()->passiveLiveness->check([
            'user_image' => File::fromBytes('u', 'u.png'),
            'metadata' => [],
        ]);

        self::assertSame([
            'user_image' => ['file:u.png:image/png:u'],
            'metadata' => ['{}'],
        ], Http::multipartFields($this->support->http->lastRequest()), 'the same input sends {} in a JSON body');
        self::assertSame(
            self::textPart('metadata', '{}') . "--B--\r\n",
            Multipart::encode(['metadata' => [], 'labels' => []], 'B', ['metadata' => ['o']]),
            'an empty list still has no multipart form',
        );
    }

    public function testJsonOrMultipartOperationStaysJsonWithoutFiles(): void
    {
        $this->support->http->queueJson(200, ['color_primary' => '#112233']);

        $this->support->client()->customization->update(['color_primary' => '#112233', 'skip_welcome_screen' => true]);

        $request = $this->support->http->lastRequest();
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"color_primary":"#112233","skip_welcome_screen":true}', (string) $request->getBody());
    }

    public function testJsonOrMultipartOperationSwitchesToMultipartWithAFile(): void
    {
        $memory = fopen('php://memory', 'w+');
        self::assertIsResource($memory);
        fwrite($memory, 'favicon-bytes');
        $this->support->http->queueJson(200, []);
        $this->support->http->queueJson(200, []);
        $client = $this->support->client();

        $client->customization->update(['color_primary' => '#112233', 'skip_welcome_screen' => true, 'image_square' => File::fromBytes('logo', 'logo.png')]);
        $client->customization->update(['image_favicon' => $memory]);
        fclose($memory);

        [$withFile, $withResource] = $this->support->http->requests;
        self::assertStringStartsWith('multipart/form-data; boundary=', $withFile->getHeaderLine('Content-Type'));
        self::assertSame([
            'color_primary' => ['#112233'],
            'skip_welcome_screen' => ['true'],
            'image_square' => ['file:logo.png:image/png:logo'],
        ], Http::multipartFields($withFile));
        self::assertSame(['image_favicon' => ['file:file:application/octet-stream:favicon-bytes']], Http::multipartFields($withResource));
    }

    public function testFileInAJsonOnlyOperationIsRejectedBeforeSending(): void
    {
        $client = $this->support->client();

        foreach ([File::fromBytes('x', 'x.png'), Utils::streamFor('x')] as $file) {
            $error = self::thrown(static fn () => $client->sessions->create(['workflow_id' => 'wf', 'metadata' => ['scan' => $file]]));

            self::assertInstanceOf(\InvalidArgumentException::class, $error);
            self::assertStringContainsString('multipart', $error->getMessage());
        }
        self::assertSame([], $this->support->http->requests);
    }

    public function testBinaryResponse(): void
    {
        $pdf = "%PDF-1.7\n\x00\x01\x02{\"not\":\"json\"}\n%%EOF";
        $this->support->http->queue(200, $pdf, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="session-abc.pdf"', 'X-Request-Id' => 'req_pdf']);

        $result = $this->support->client()->sessions->generatePdf('abc');

        $request = $this->support->http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v3/session/abc/generate-pdf/', $request->getUri()->getPath());
        self::assertSame('application/pdf, application/json', $request->getHeaderLine('Accept'));
        self::assertFalse($request->hasHeader('Content-Type'));
        self::assertSame($pdf, $result->contents());
        self::assertSame($pdf, $result->contents(), 'contents can be read twice');
        self::assertSame($pdf, $result->stream()->getContents(), 'the stream is rewound');
        self::assertSame('application/pdf', $result->contentType());
        self::assertSame('session-abc.pdf', $result->filename());
        self::assertSame('req_pdf', $result->rawResponse()->requestId);
        self::assertSame(200, $result->rawResponse()->status);

        $target = $this->dir . '/saved.pdf';
        $result->saveTo($target);
        self::assertSame($pdf, file_get_contents($target));
    }

    public function testBinaryFileNameFromContentDisposition(): void
    {
        $this->support->http->queue(200, 'a', ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename*=UTF-8''r%C3%A9port%20final.csv"]);
        $this->support->http->queue(200, 'b', ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="../../etc/passwd"']);
        $this->support->http->queue(200, 'c', ['Content-Type' => 'text/csv']);
        $imports = $this->support->client()->sessions->imports;

        self::assertSame('réport final.csv', $imports->retrieveTemplate(['import_type' => 'sessions'])->filename());
        self::assertSame('passwd', $imports->retrieveTemplate()->filename(), 'no directory part');
        self::assertNull($imports->retrieveTemplate()->filename());

        self::assertSame('text/csv, application/json', $this->support->http->requests[0]->getHeaderLine('Accept'));
        self::assertSame(['import_type' => 'sessions'], Http::queryParams($this->support->http->requests[0]));
    }

    public function testBinaryOperationErrorStillRaisesTheMappedException(): void
    {
        $this->support->http->queueJson(404, ['detail' => 'Session not found.'], ['X-Request-Id' => 'req_404']);

        try {
            $this->support->client()->sessions->generatePdf('missing');
            self::fail('no exception');
        } catch (NotFoundException $e) {
            self::assertSame(['detail' => 'Session not found.'], $e->body);
            self::assertSame('req_404', $e->requestId);
            self::assertStringContainsString('Session not found.', $e->getMessage());
        }
    }

    public function testNoContentExposesTheRawResponse(): void
    {
        $this->support->http->queue(204, '', ['X-Request-Id' => 'req_204']);

        $raw = $this->support->client()->cases->delete('case-1')->rawResponse();

        self::assertNotNull($raw);
        self::assertSame(204, $raw->status);
        self::assertSame('req_204', $raw->requestId);
        self::assertSame('DELETE', $raw->method);
        self::assertSame('/v3/organization/org-7f3a/application/app-91c2/cases/case-1/', $raw->path);
        self::assertSame('', $raw->body());
        self::assertNull($raw->parsedBody());
        self::assertSame('DELETE', $this->support->http->lastRequest()->getMethod());
    }

    private static function textPart(string $name, string $value): string
    {
        return "--B\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function thrown(\Closure $call): ?\Throwable
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function tempFile(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        self::assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }

    private static function boundary(RequestInterface $request): string
    {
        self::assertSame(1, preg_match('#^multipart/form-data; boundary=(didit-[0-9a-f]{32})$#', $request->getHeaderLine('Content-Type'), $match));

        return $match[1];
    }
}
