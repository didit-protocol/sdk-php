<?php

declare(strict_types=1);

namespace Didit\Tests\Unit;

use Didit\Exception\ConfigurationException;
use Didit\RequestOptions;
use Didit\Tests\Support\Http;
use Didit\Tests\Support\TestClient;
use Didit\Version;
use PHPUnit\Framework\TestCase;

/**
 * Headers, authentication, paths, query strings and request bodies.
 *
 * @internal
 */
final class RequestTest extends TestCase
{
    private TestClient $support;

    protected function setUp(): void
    {
        $this->support = new TestClient();
    }

    public function testEveryRequestCarriesTheSdkHeaders(): void
    {
        $this->support->http->queueJson(200, ['session_id' => 's']);
        $this->support->client()->sessions->retrieveDecision('abc');

        $request = $this->support->http->lastRequest();
        self::assertSame(TestClient::API_KEY, $request->getHeaderLine('x-api-key'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('php/' . Version::VERSION, $request->getHeaderLine('X-Didit-Client'));
        self::assertMatchesRegularExpression('#^didit-php/\d+\.\d+\.\d+\S* \(PHP \d+\.\d+\.\d+\S*; \w+\)$#', $request->getHeaderLine('User-Agent'));
        self::assertSame('', $request->getHeaderLine('Authorization'));
    }

    public function testDefaultAndPerRequestHeadersCannotReplaceCredentials(): void
    {
        $options = TestClient::options(defaultHeaders: ['X-Trace' => 'default', 'X-Api-Key' => 'spoofed']);
        $this->support->http->queueJson(200, []);
        $this->support->client($options)->billing->retrieveBalance(new RequestOptions(headers: ['X-Trace' => 'request', 'Authorization' => 'Bearer spoofed']));

        $request = $this->support->http->lastRequest();
        self::assertSame('request', $request->getHeaderLine('X-Trace'));
        self::assertSame(TestClient::API_KEY, $request->getHeaderLine('x-api-key'));
        self::assertSame('', $request->getHeaderLine('Authorization'));
    }

    public function testPerRequestApiKeyOverride(): void
    {
        $this->support->http->queueJson(200, []);
        $this->support->client()->billing->retrieveBalance(new RequestOptions(apiKey: 'other_key'));

        self::assertSame('other_key', $this->support->http->lastRequest()->getHeaderLine('x-api-key'));
    }

    public function testApplicationScopedPathsAreFilledFromOptions(): void
    {
        $this->support->http->queueJson(200, ['count' => 0, 'next' => null, 'previous' => null, 'results' => []]);
        $this->support->client()->cases->list();

        self::assertSame('/v3/organization/org-7f3a/application/app-91c2/cases/', $this->support->http->lastRequest()->getUri()->getPath());
    }

    public function testApplicationScopedPathsCanBeOverriddenPerRequest(): void
    {
        $this->support->http->queueJson(200, ['count' => 0, 'next' => null, 'previous' => null, 'results' => []]);
        $this->support->client()->cases->list([], new RequestOptions(organizationId: 'org-2', applicationId: 'app-2'));

        self::assertSame('/v3/organization/org-2/application/app-2/cases/', $this->support->http->lastRequest()->getUri()->getPath());
    }

    public function testApplicationScopeWithoutIdsFailsBeforeSending(): void
    {
        $client = $this->support->client(TestClient::options(organizationId: null));

        try {
            $client->cases->list();
            self::fail('no exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('DIDIT_ORGANIZATION_ID', $e->getMessage());
        }
        self::assertSame([], $this->support->http->requests);
    }

    public function testMissingApiKeyFailsBeforeSending(): void
    {
        $client = $this->support->client(TestClient::options(apiKey: null));

        $this->expectException(ConfigurationException::class);
        try {
            $client->billing->retrieveBalance();
        } finally {
            self::assertSame([], $this->support->http->requests);
        }
    }

    public function testAccountOperationsUseTheBearerTokenAndNeverTheApiKey(): void
    {
        $this->support->http->queueJson(200, []);
        $this->support->client()->account->organizations->list();

        $request = $this->support->http->lastRequest();
        self::assertSame('apx.didit.me', $request->getUri()->getHost());
        self::assertSame('/auth/v2/organizations/me/', $request->getUri()->getPath());
        self::assertSame('Bearer ' . TestClient::ACCOUNT_TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame('', $request->getHeaderLine('x-api-key'));
    }

    public function testAccountLoginSendsNoCredentials(): void
    {
        $this->support->http->queueJson(200, ['access_token' => 'a']);
        $this->support->client()->account->login(['email' => 'dev@example.com', 'password' => 'pw']);

        $request = $this->support->http->lastRequest();
        self::assertSame('', $request->getHeaderLine('x-api-key'));
        self::assertSame('', $request->getHeaderLine('Authorization'));
    }

    public function testMissingAccountTokenFailsBeforeSending(): void
    {
        $client = $this->support->client(TestClient::options(accountToken: null));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('account token');
        $client->account->organizations->list();
    }

    public function testPathParametersAreEncoded(): void
    {
        $this->support->http->queueJson(200, []);
        $this->support->client()->users->retrieve('user/42 ü');

        self::assertSame('/v3/users/user%2F42%20%C3%BC/', $this->support->http->lastRequest()->getUri()->getPath());
    }

    public function testEmptyPathParameterIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->support->client()->users->retrieve('');
    }

    public function testQueryValuesAreEncoded(): void
    {
        $this->support->http->queueJson(200, ['count' => 0, 'next' => null, 'previous' => null, 'results' => []]);
        $this->support->client()->sessions->list(
            ['limit' => 5, 'vendor_data' => 'a b&c'],
            new RequestOptions(extraQuery: ['flag' => true, 'new_filter' => 'x']),
        );

        self::assertSame(
            ['limit' => '5', 'vendor_data' => 'a b&c', 'flag' => 'true', 'new_filter' => 'x'],
            Http::queryParams($this->support->http->lastRequest()),
        );
    }

    public function testJsonBodyEncoding(): void
    {
        $this->support->http->queueJson(201, ['session_id' => 's']);
        $this->support->client()->sessions->create([
            'workflow_id' => 'wf',
            'vendor_data' => 'café/42',
            'metadata' => [],
            'contact_details' => ['email' => 'a@example.com'],
        ]);

        $request = $this->support->http->lastRequest();
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"workflow_id":"wf","vendor_data":"café/42","metadata":{},"contact_details":{"email":"a@example.com"}}', (string) $request->getBody());
    }

    public function testDateTimesAreSentAsIso8601(): void
    {
        $this->support->http->queueJson(201, []);
        $this->support->client()->transactions->create([
            'transaction_id' => 't1',
            'transaction_at' => new \DateTimeImmutable('2026-03-01T12:00:00+02:00'),
            'transaction_category' => 'finance',
            'transaction_details' => ['direction' => 'inbound', 'amount' => 10.0, 'currency' => 'EUR'],
            'subject' => ['entity_type' => 'individual', 'vendor_data' => 'u1', 'full_name' => 'Ada Lovelace'],
        ]);

        $body = json_decode((string) $this->support->http->lastRequest()->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('2026-03-01T12:00:00+02:00', $body['transaction_at']);
        self::assertIsArray($body['transaction_details']);
        self::assertSame(10.0, $body['transaction_details']['amount']);
    }

    public function testUnknownParametersArePassedThrough(): void
    {
        $this->support->http->queueJson(201, []);
        $this->support->client()->sessions->create(['workflow_id' => 'wf', 'future_field' => 1]);

        self::assertStringContainsString('"future_field":1', (string) $this->support->http->lastRequest()->getBody());
    }

    public function testHeaderParameters(): void
    {
        $this->support->http->queueJson(200, []);
        $this->support->client()->aml->screen(['full_name' => 'John Doe', 'idempotency_key' => 'idem-1']);

        $request = $this->support->http->lastRequest();
        self::assertSame('idem-1', $request->getHeaderLine('Idempotency-Key'));
        self::assertStringNotContainsString('idempotency_key', (string) $request->getBody());
    }

    public function testRawResponseIsExposed(): void
    {
        $this->support->http->queueJson(200, ['session_id' => 's', 'brand_new_field' => ['x' => 1]], ['X-Request-Id' => 'req_123']);
        $decision = $this->support->client()->sessions->retrieveDecision('s');

        self::assertSame('s', $decision->sessionId);
        self::assertSame(['x' => 1], $decision['brand_new_field']);
        self::assertTrue($decision->has('brand_new_field'));
        self::assertSame('req_123', $decision->rawResponse()?->requestId);
        self::assertSame(200, $decision->rawResponse()->status);
        self::assertSame('application/json', $decision->rawResponse()->header('content-type'));
    }
}
