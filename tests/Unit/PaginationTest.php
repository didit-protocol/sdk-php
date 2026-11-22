<?php

declare(strict_types=1);

namespace Didit\Tests\Unit;

use Didit\Core\ClientOptions;
use Didit\Core\HttpTransport;
use Didit\Core\Page;
use Didit\Core\Requester;
use Didit\Exception\DiditException;
use Didit\RequestOptions;
use Didit\Tests\Support\Http;
use Didit\Tests\Support\MockHttpClient;
use Didit\Tests\Support\TestClient;
use Didit\Types\SessionsListResponseResults;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pages, auto-pagination and the origin check on `next` links (DESIGN sections 6 and 8).
 *
 * @internal
 */
final class PaginationTest extends TestCase
{
    private const BASE = 'https://verification.didit.me';

    private TestClient $support;

    protected function setUp(): void
    {
        $this->support = new TestClient();
    }

    public function testPageFields(): void
    {
        $this->support->http->queueJson(200, self::page(['s1', 's2'], self::BASE . '/v3/sessions/?limit=2&offset=2', ['kpis' => ['approved' => 3]]), ['X-Request-Id' => 'req_page']);

        $page = $this->support->client()->sessions->list(['limit' => 2]);

        self::assertSame(5, $page->count);
        self::assertSame(self::BASE . '/v3/sessions/?limit=2&offset=2', $page->next);
        self::assertNull($page->previous);
        self::assertCount(2, $page->results);
        self::assertContainsOnlyInstancesOf(SessionsListResponseResults::class, $page->results);
        self::assertSame('s1', $page->results[0]->sessionId);
        self::assertSame('s2', $page->results[1]->sessionId);
        self::assertTrue($page->hasNextPage());
        self::assertTrue(isset($page['kpis']));
        self::assertSame(['approved' => 3], $page['kpis']);
        self::assertFalse(isset($page['missing']));
        self::assertNull($page['missing']);
        self::assertSame(self::page(['s1', 's2'], self::BASE . '/v3/sessions/?limit=2&offset=2', ['kpis' => ['approved' => 3]]), $page->toArray());
        self::assertSame('req_page', $page->rawResponse()?->requestId);
        self::assertSame(['limit' => '2'], Http::queryParams($this->support->http->lastRequest()));
    }

    public function testPageIsReadOnly(): void
    {
        $this->support->http->queueJson(200, self::page(['s1'], null));
        $page = $this->support->client()->sessions->list();

        $this->expectException(\LogicException::class);
        $page['kpis'] = 1;
    }

    public function testNextPageFollowsTheLinkAndStopsOnTheLastPage(): void
    {
        $this->support->http->queueJson(200, self::page(['s1', 's2'], self::BASE . '/v3/sessions/?limit=2&offset=2'));
        $this->support->http->queueJson(200, self::page(['s3'], null, [], self::BASE . '/v3/sessions/?limit=2'));

        $first = $this->support->client()->sessions->list(['limit' => 2]);
        $second = $first->nextPage();

        self::assertNotNull($second);
        self::assertSame(['s3'], self::ids($second->results));
        self::assertSame(self::BASE . '/v3/sessions/?limit=2', $second->previous);
        self::assertFalse($second->hasNextPage());
        self::assertNull($second->nextPage());
        self::assertSame(self::BASE . '/v3/sessions/?limit=2&offset=2', (string) $this->support->http->lastRequest()->getUri());
        self::assertCount(2, $this->support->http->requests);
    }

    public function testForeachWalksEveryPageFollowingNextLinks(): void
    {
        $this->support->http->queueJson(200, self::page(['s1', 's2'], self::BASE . '/v3/sessions/?limit=2&offset=2&status=Approved'));
        $this->support->http->queueJson(200, self::page(['s3', 's4'], '/v3/sessions/?limit=2&offset=4&status=Approved'));
        $this->support->http->queueJson(200, self::page(['s5'], null));

        $page = $this->support->client()->sessions->list(['limit' => 2, 'status' => 'Approved']);
        $seen = [];
        foreach ($page as $index => $session) {
            $seen[$index] = $session->sessionId;
        }

        self::assertSame(['s1', 's2', 's3', 's4', 's5'], $seen, 'keys run 0..n-1 across pages');
        self::assertSame(
            [
                self::BASE . '/v3/sessions/?limit=2&status=Approved',
                self::BASE . '/v3/sessions/?limit=2&offset=2&status=Approved',
                self::BASE . '/v3/sessions/?limit=2&offset=4&status=Approved',
            ],
            $this->uris(),
        );
        foreach ($this->support->http->requests as $request) {
            self::assertSame('GET', $request->getMethod());
            self::assertSame(TestClient::API_KEY, $request->getHeaderLine('x-api-key'), 'the API key is sent on every page');
            self::assertSame('application/json', $request->getHeaderLine('Accept'));
        }

        self::assertSame(['s1', 's2'], self::ids($page->results), 'the first page keeps its own results');
    }

    public function testRequestOptionsApplyToEveryPage(): void
    {
        $this->support->http->queueJson(200, self::page(['s1'], self::BASE . '/v3/sessions/?offset=1'));
        $this->support->http->queueJson(200, self::page(['s2'], null));

        $page = $this->support->client()->sessions->list([], new RequestOptions(apiKey: 'test_api_key_override', headers: ['X-Trace' => 't-1']));
        self::assertSame(['s1', 's2'], self::ids(iterator_to_array($page, false)));

        foreach ($this->support->http->requests as $request) {
            self::assertSame('test_api_key_override', $request->getHeaderLine('x-api-key'));
            self::assertSame('t-1', $request->getHeaderLine('X-Trace'));
        }
    }

    public function testEachStopsWhenTheCallbackReturnsFalse(): void
    {
        $this->support->http->queueJson(200, self::page(['s1', 's2'], '/v3/sessions/?offset=2'));
        $this->support->http->queueJson(200, self::page(['s3', 's4'], '/v3/sessions/?offset=4'));
        $this->support->http->queueJson(200, self::page(['s5'], null));

        $seen = [];
        $this->support->client()->sessions->list()->each(static function (SessionsListResponseResults $session) use (&$seen): ?bool {
            $seen[] = $session->sessionId;

            return 's3' === $session->sessionId ? false : null;
        });

        self::assertSame(['s1', 's2', 's3'], $seen);
        self::assertCount(2, $this->support->http->requests, 'the third page is never fetched');
    }

    public function testEachVisitsEveryItemWhenTheCallbackReturnsNothing(): void
    {
        $this->support->http->queueJson(200, self::page(['s1'], '/v3/sessions/?offset=1'));
        $this->support->http->queueJson(200, self::page(['s2'], null));

        $seen = [];
        $this->support->client()->sessions->list()->each(static function (SessionsListResponseResults $session) use (&$seen): void {
            $seen[] = $session->sessionId;
        });

        self::assertSame(['s1', 's2'], $seen);
    }

    public function testEmptyResultsStopIterationEvenWhenNextIsSet(): void
    {
        $this->support->http->queueJson(200, self::page([], self::BASE . '/v3/sessions/?offset=2'));

        $page = $this->support->client()->sessions->list();

        self::assertFalse($page->hasNextPage());
        self::assertNull($page->nextPage());
        self::assertSame([], iterator_to_array($page));
        self::assertCount(1, $this->support->http->requests);
    }

    public function testEmptyLaterPageStopsIteration(): void
    {
        $this->support->http->queueJson(200, self::page(['s1'], '/v3/sessions/?offset=1'));
        $this->support->http->queueJson(200, self::page([], '/v3/sessions/?offset=2'));
        $this->support->http->queueJson(200, self::page(['never'], null));

        $ids = self::ids(iterator_to_array($this->support->client()->sessions->list(), false));

        self::assertSame(['s1'], $ids);
        self::assertCount(2, $this->support->http->requests);
        self::assertSame(1, $this->support->http->remaining());
    }

    public function testEmptyNextStopsIteration(): void
    {
        $this->support->http->queueJson(200, self::page(['s1'], ''));

        $page = $this->support->client()->sessions->list();

        self::assertFalse($page->hasNextPage());
        self::assertCount(1, iterator_to_array($page));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedLinks(): iterable
    {
        yield 'other host' => ['https://evil.example.com/v3/sessions/?offset=2'];
        yield 'subdomain' => ['https://api.verification.didit.me/v3/sessions/?offset=2'];
        yield 'host suffix' => ['https://verification.didit.me.evil.example.com/v3/sessions/?offset=2'];
        yield 'other port' => ['https://verification.didit.me:8443/v3/sessions/?offset=2'];
        yield 'other scheme' => ['ftp://verification.didit.me/v3/sessions/?offset=2'];
        yield 'http with an explicit port' => ['http://verification.didit.me:80/v3/sessions/?offset=2'];
        yield 'protocol-relative other host' => ['//evil.example.com/v3/sessions/?offset=2'];
        yield 'userinfo' => ['https://user:pass@verification.didit.me/v3/sessions/?offset=2'];
        yield 'user only' => ['https://user@verification.didit.me/v3/sessions/?offset=2'];
        yield 'userinfo pointing elsewhere' => ['https://verification.didit.me@evil.example.com/v3/sessions/?offset=2'];
    }

    #[DataProvider('refusedLinks')]
    public function testLinksToAnotherOriginAreRefusedBeforeAnyRequest(string $next): void
    {
        $this->support->http->queueJson(200, self::page(['s1', 's2'], $next));
        $this->support->http->queueJson(200, self::page(['stolen'], null));

        [$seen, $error] = self::drain($this->support->client()->sessions->list());

        self::assertInstanceOf(DiditException::class, $error, 'the link was refused');
        self::assertStringContainsString('another origin', $error->getMessage());
        self::assertSame(['s1', 's2'], $seen, 'the first page is still delivered');
        self::assertCount(1, $this->support->http->requests, 'no request after the first page');
        self::assertSame(1, $this->support->http->remaining());
    }

    #[DataProvider('refusedLinks')]
    public function testNextPageRefusesLinksToAnotherOrigin(string $next): void
    {
        $this->support->http->queueJson(200, self::page(['s1'], $next));
        $page = $this->support->client()->sessions->list();

        $this->expectException(DiditException::class);
        try {
            $page->nextPage();
        } finally {
            self::assertCount(1, $this->support->http->requests);
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function followedLinks(): iterable
    {
        yield 'absolute' => ['https://verification.didit.me/v3/sessions/?limit=2&offset=2', 'https://verification.didit.me/v3/sessions/?limit=2&offset=2', '/v3/sessions/'];
        yield 'default port spelled out' => ['https://verification.didit.me:443/v3/sessions/?offset=2', 'https://verification.didit.me/v3/sessions/?offset=2', '/v3/sessions/'];
        yield 'host in upper case' => ['HTTPS://VERIFICATION.DIDIT.ME/v3/sessions/?offset=2', 'https://verification.didit.me/v3/sessions/?offset=2', '/v3/sessions/'];
        yield 'http upgraded to https' => ['http://verification.didit.me/v3/sessions/?offset=2', 'https://verification.didit.me/v3/sessions/?offset=2', '/v3/sessions/'];
        yield 'relative' => ['/v3/sessions/?offset=2&search=a%20b', 'https://verification.didit.me/v3/sessions/?offset=2&search=a%20b', '/v3/sessions/'];
        yield 'relative without leading slash' => ['v3/sessions/?offset=2', 'https://verification.didit.me/v3/sessions/?offset=2', '/v3/sessions/'];
        yield 'fragment dropped' => ['/v3/sessions/?offset=2#top', 'https://verification.didit.me/v3/sessions/?offset=2', '/v3/sessions/'];
    }

    #[DataProvider('followedLinks')]
    public function testSameOriginLinksAreFollowed(string $next, string $expectedUrl, string $expectedPath): void
    {
        self::assertSame([$expectedUrl, $expectedPath], self::requester()->resolveNextUrl($next, self::BASE));

        $this->support->http->queueJson(200, self::page(['s1'], $next));
        $this->support->http->queueJson(200, self::page(['s2'], null));

        $ids = self::ids(iterator_to_array($this->support->client()->sessions->list(), false));

        self::assertSame(['s1', 's2'], $ids);
        self::assertSame($expectedUrl, (string) $this->support->http->lastRequest()->getUri());
        self::assertSame(TestClient::API_KEY, $this->support->http->lastRequest()->getHeaderLine('x-api-key'));
    }

    public function testCustomHttpBaseUrlOnlyFollowsItsOwnOrigin(): void
    {
        $requester = self::requester();
        $base = 'http://localhost:8080';

        self::assertSame(['http://localhost:8080/v3/sessions/?offset=2', '/v3/sessions/'], $requester->resolveNextUrl('http://localhost:8080/v3/sessions/?offset=2', $base));
        foreach ([
            'https://localhost:8080/v3/sessions/?offset=2',
            'http://localhost:9090/v3/sessions/?offset=2',
            'http://localhost/v3/sessions/?offset=2',
            'http://127.0.0.1:8080/v3/sessions/?offset=2',
        ] as $next) {
            try {
                $requester->resolveNextUrl($next, $base);
                self::fail("{$next} was accepted");
            } catch (DiditException $e) {
                self::assertStringContainsString('another origin', $e->getMessage());
            }
        }
    }

    public function testHttpUpgradeNeedsTheDefaultHttpsPort(): void
    {
        $this->expectException(DiditException::class);
        self::requester()->resolveNextUrl('http://localhost/v3/sessions/', 'https://localhost:8443');
    }

    public function testFollowingAPageUsesTheConfiguredOriginAndPort(): void
    {
        $client = $this->support->client(TestClient::options(baseUrl: 'http://localhost:8080'));
        $this->support->http->queueJson(200, self::page(['s1'], 'http://localhost:8080/v3/sessions/?offset=1'));
        $this->support->http->queueJson(200, self::page(['s2'], null));

        self::assertSame(['s1', 's2'], self::ids(iterator_to_array($client->sessions->list(), false)));
        self::assertSame(['http://localhost:8080/v3/sessions/', 'http://localhost:8080/v3/sessions/?offset=1'], $this->uris());
    }

    public function testPageWithoutFetcherOnlyIteratesItsOwnResults(): void
    {
        $page = new Page([1, 2, 3], 9, 'https://verification.didit.me/v3/sessions/?offset=3', null, ['extra' => true]);

        self::assertTrue($page->hasNextPage());
        self::assertNull($page->nextPage());
        self::assertSame([1, 2, 3], iterator_to_array($page));
        self::assertNull($page->rawResponse());
        self::assertTrue($page['extra']);
    }

    public function testListResponseIterationAndCount(): void
    {
        $destinations = [
            ['uuid' => 'd1', 'url' => 'https://example.com/hook-1', 'enabled' => true],
            ['uuid' => 'd2', 'url' => 'https://example.com/hook-2', 'enabled' => false, 'brand_new' => 1],
        ];
        $this->support->http->queueJson(200, $destinations, ['X-Request-Id' => 'req_list']);

        $list = $this->support->client()->webhooks->destinations->list();

        self::assertCount(2, $list);
        self::assertCount(2, $list->items);
        $uuids = [];
        foreach ($list as $index => $destination) {
            $uuids[$index] = $destination->uuid;
        }
        self::assertSame(['d1', 'd2'], $uuids);
        $first = $list[0];
        $second = $list[1];
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame('https://example.com/hook-1', $first->url);
        self::assertFalse($second->enabled);
        self::assertSame(1, $second->get('brand_new'));
        self::assertTrue(isset($list[1]));
        self::assertFalse(isset($list[2]));
        self::assertNull($list[2]);
        self::assertSame($destinations, $list->toArray());
        self::assertSame($destinations, $list->jsonSerialize());
        self::assertSame('req_list', $list->rawResponse()?->requestId);
        self::assertCount(1, $this->support->http->requests, 'a JSON array is not paginated');
    }

    public function testListResponseIgnoresANonArrayBody(): void
    {
        $this->support->http->queueJson(200, ['detail' => 'not a list']);

        $list = $this->support->client()->webhooks->destinations->list();

        self::assertCount(0, $list);
        self::assertSame([], $list->toArray());
        self::assertSame([], iterator_to_array($list));
    }

    public function testListResponseIsReadOnly(): void
    {
        $this->support->http->queueJson(200, []);
        $list = $this->support->client()->webhooks->destinations->list();

        $this->expectException(\LogicException::class);
        unset($list[0]);
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function page(array $ids, ?string $next, array $extra = [], ?string $previous = null): array
    {
        return [
            'count' => 5,
            'next' => $next,
            'previous' => $previous,
            'results' => array_map(static fn (string $id): array => ['session_id' => $id], $ids),
        ] + $extra;
    }

    /**
     * @param array<SessionsListResponseResults> $sessions
     *
     * @return list<string|null>
     */
    private static function ids(array $sessions): array
    {
        return array_values(array_map(static fn (SessionsListResponseResults $s): ?string => $s->sessionId, $sessions));
    }

    /**
     * @return list<string>
     */
    private function uris(): array
    {
        return array_map(static fn ($request): string => (string) $request->getUri(), $this->support->http->requests);
    }

    /**
     * Iterates a page until the end or the first error.
     *
     * @param iterable<SessionsListResponseResults> $page
     *
     * @return array{list<string|null>, \Throwable|null} the session ids seen and the error
     */
    private static function drain(iterable $page): array
    {
        $seen = [];
        try {
            foreach ($page as $session) {
                $seen[] = $session->sessionId;
            }
        } catch (\Throwable $e) {
            return [$seen, $e];
        }

        return [$seen, null];
    }

    private static function requester(): Requester
    {
        $factory = new HttpFactory();

        return new Requester(TestClient::options(baseUrl: ClientOptions::DEFAULT_BASE_URL), new HttpTransport(new MockHttpClient()), $factory, $factory);
    }
}
