# Didit PHP SDK

The official Didit PHP library.
Identity verification (KYC), business verification (KYB), AML screening, biometrics, transaction monitoring, wallet screening, Travel Rule and customer authentication, from your backend, with typed requests and responses for every Didit server API.

- API reference: [docs.didit.me](https://docs.didit.me)
- Design contract shared by every Didit server SDK: [docs/DESIGN.md](docs/DESIGN.md)
- Runnable examples, including Laravel and Symfony webhook receivers: [examples/](examples/)

## Requirements

PHP 8.1, 8.2, 8.3 or 8.4 with `ext-json` (and `ext-openssl` for Authentication), plus any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client.
[Guzzle](https://docs.guzzlephp.org) is recommended; Symfony HttpClient works too.

## Install

```bash
composer require didit-protocol/sdk-php guzzlehttp/guzzle
```

## Quickstart

Create a verification session, send the user to its URL, read the decision, and verify the webhook Didit sends when the status changes.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Didit\DiditClient;
use Didit\Webhooks\Events\StatusUpdatedEvent;

$didit = new DiditClient(apiKey: getenv('DIDIT_API_KEY'));

// 1. Create a session and redirect the user to its hosted URL.
$session = $didit->sessions->create([
    'workflow_id' => 'your-workflow-id',
    'vendor_data' => 'user-42',
    'callback' => 'https://example.com/verification/done',
]);
echo $session->url, "\n";

// 2. Read the decision later.
$decision = $didit->sessions->retrieveDecision($session->sessionId);
echo $decision->status, "\n"; // "Approved", "Declined", "In Review", ...

// 3. Verify a webhook in your endpoint, with the raw body.
$event = $didit->webhooks->verify(
    file_get_contents('php://input'),
    getallheaders(),
    getenv('DIDIT_WEBHOOK_SECRET'),
);
if ($event instanceof StatusUpdatedEvent && 'Approved' === $event->status) {
    // Mark user $event->vendorData as verified.
}
```

[examples/quickstart.php](examples/quickstart.php) runs the same flow end to end.

## Configuration

```php
$didit = new DiditClient(
    apiKey: 'your-api-key',            // default: DIDIT_API_KEY
    organizationId: 'org-uuid',        // default: DIDIT_ORGANIZATION_ID
    applicationId: 'app-uuid',         // default: DIDIT_APPLICATION_ID
    baseUrl: 'https://verification.didit.me',
    timeout: 60.0,                     // seconds per attempt
    maxRetries: 2,
    defaultHeaders: ['X-Correlation-Id' => 'abc'],
    httpClient: $anyPsr18Client,       // default: Guzzle, then Symfony HttpClient, then discovery
    logger: $psr3Logger,               // debug lines only; DIDIT_LOG=debug logs to stderr
);
```

- Operations under `/v3/organization/{organization_id}/application/{application_id}/` take both ids from `organizationId` and `applicationId`; calling one without them throws `ConfigurationException` before any request is sent.
- Every option can change for one request with `RequestOptions`, or for a copy of the client with `withOptions()`:

```php
use Didit\RequestOptions;

$page = $didit->sessions->list(['limit' => 10], new RequestOptions(timeout: 5.0, maxRetries: 0));
$sandbox = $didit->withOptions(apiKey: getenv('DIDIT_SANDBOX_API_KEY'));
```

- Every request sends `x-api-key`, `User-Agent: didit-php/<version> (PHP <version>; <OS>)`, `X-Didit-Client: php/<version>` and `Accept: application/json`.
- The API key is never logged, never part of an exception message and only sent to `baseUrl`; a pagination link to another origin is refused. `var_dump($didit)` shows it as `[redacted]`.
- Debug logging (`DIDIT_LOG=debug` or a PSR-3 `logger`) writes the method, path, status, duration and request id of each attempt, never bodies or headers.
- The client is immutable: create one per process (or per request in PHP-FPM) and reuse it.

## Calling the API

Resources and methods follow the API reference: `$didit-><resource>-><method>()`, nested resources included.

```php
$didit->sessions->create([...]);
$didit->sessions->imports->create([...]);
$didit->idVerification->verify([...]);
$didit->aml->screen(['full_name' => 'John Smith', 'date_of_birth' => '1980-01-01']);
$didit->users->list(['limit' => 20]);
$didit->cases->notes->create($caseId, [...]);
$didit->webhooks->destinations->list();
```

Parameters are plain arrays.
Each one has a PHPStan array shape (`Didit\Params`), so PHPStan and your IDE check field names and types; unknown keys are sent as given, for API fields newer than this SDK.
Path parameters are positional arguments.
Responses are read-only objects in `Didit\Types` with typed properties (`DateTimeImmutable` for date-times, string decimals stay strings).
Every field the API sent stays readable, including fields this SDK version does not know yet:

```php
$decision->status;              // typed property
$decision->toArray();           // every field, exactly as decoded
$decision['new_field'];         // array access by API name
$decision->has('expected_details'); // sent at all, even as null?
```

Enum fields are strings with known values as constants (for example `Didit\Types\SessionsListParamsSessionKind::BUSINESS`); the API may add values, so keep a default branch.

### Raw responses

Every result exposes the HTTP response behind it:

```php
$raw = $session->rawResponse();
$raw->status;               // 201
$raw->requestId;            // X-Request-Id, quote it to support
$raw->header('Content-Type');
$raw->psrResponse;          // the PSR-7 response
```

## Errors

| Exception (namespace `Didit\Exception`) | When |
|---|---|
| `DiditException` | base of everything below |
| `ApiConnectionException`, `ApiTimeoutException` | no response |
| `ApiStatusException` | any non-2xx answer, with `status`, `body` (parsed JSON when possible), `headers`, `requestId` |
| `BadRequestException` 400, `AuthenticationException` 401, `PermissionDeniedException` 403, `NotFoundException` 404, `ConflictException` 409, `UnprocessableEntityException` 422, `RateLimitException` 429, `InternalServerException` 5xx | subclasses of `ApiStatusException` |
| `WebhookVerificationException` | webhook verification failed; `reason` is machine-readable |
| `ConfigurationException` | missing API key, missing organization or application id, invalid options |

```php
use Didit\Exception\ApiStatusException;
use Didit\Exception\NotFoundException;

try {
    $didit->sessions->retrieveDecision($sessionId);
} catch (NotFoundException) {
    // unknown session
} catch (ApiStatusException $e) {
    error_log(sprintf('Didit answered %d (request %s)', $e->status, $e->requestId));
}
```

### Retries and timeouts

Failed requests are retried up to `maxRetries` times (default 2) with exponential backoff from 0.5 s with full jitter, capped at 8 s, honouring `Retry-After` (capped at 60 s).
`GET`, `HEAD`, `PUT` and `DELETE` are retried on connection errors, timeouts, 408, 429 and 5xx.
`POST` and `PATCH` are never retried once the request may have reached the server (a 5xx or a read timeout); they are retried only when the connection failed before sending, or on 429.
`timeout` (default 60 s) applies to each attempt and is set natively on Guzzle and Symfony HttpClient 6.2 or later; with another PSR-18 client (or an older Symfony HttpClient), configure the timeout on that client.

## Pagination

List operations return a `Page` with `count`, `next`, `previous` and `results`.
Iterating a page walks every item of every page, fetching the next page only when needed:

```php
foreach ($didit->sessions->list(['limit' => 100, 'status' => 'Approved']) as $session) {
    echo $session->sessionId, "\n";
}

$page = $didit->users->list(['limit' => 50]);
$page->results;      // this page only
$page->nextPage();   // the next Page, or null
$page->each(function ($user) {
    // return false to stop
});
```

## Files and binary responses

Upload operations (standalone ID verification, face match, proof of address, document uploads) take a path, bytes or a stream:

```php
use Didit\File;

$result = $didit->idVerification->verify([
    'front_image' => File::fromPath('/path/to/front.jpg'),
    'back_image' => File::fromBytes($bytes, 'back.jpg', 'image/jpeg'),
]);
```

A PSR-7 stream or a PHP stream resource works too.
PDF reports and other binary responses return `BinaryContent`:

```php
$pdf = $didit->sessions->generatePdf($sessionId);
$pdf->saveTo('/tmp/report.pdf');   // or ->contents(), ->stream(), ->contentType()
```

## Webhooks

`Webhook::verify()` checks a delivery and returns a typed event. It needs no client, network or framework.

```php
use Didit\Exception\WebhookVerificationException;
use Didit\Webhooks\Events;
use Didit\Webhooks\Webhook;

try {
    $event = Webhook::verify($rawBody, $headers, $secret);   // or $didit->webhooks->verify(...)
} catch (WebhookVerificationException $e) {
    http_response_code(401);   // $e->reason: missing_signature, invalid_signature, timestamp_out_of_tolerance, ...
    exit;
}

match (true) {
    $event instanceof Events\StatusUpdatedEvent => handleSession($event->sessionId, $event->status, $event->decision),
    $event instanceof Events\TransactionStatusUpdatedEvent => handleTransaction($event->transactionId, $event->status),
    $event instanceof Events\UnknownEvent => null,   // a type this SDK version does not know: $event->toArray()
    default => null,
};
```

- Pass the raw body exactly as received (`file_get_contents('php://input')`, Laravel `$request->getContent()`, Symfony `$request->getContent()`) and the headers in any form: `getallheaders()`, `$request->headers->all()` or a PSR-7 request.
- `X-Signature` (raw body) and `X-Signature-V2` (canonical JSON, survives middleware that re-encodes the body) are accepted, plus their `-Previous` variants during a secret rotation. Pass several secrets as an array while you rotate.
- The timestamp must be within `tolerance` seconds (default 300). Signatures are compared in constant time.
- The deprecated `X-Signature-Simple` counts only with `allowSimple: true`, and then `$event->bodyAuthenticated()` is false: re-fetch the record from the API before trusting other fields.
- `Webhook::verifyParsed($payload, $headers, $secret)` exists for code that only has the decoded body; it can check `X-Signature-V2` only. Prefer `verify()`.
- Didit retries deliveries with the same `eventId`: use it to ignore duplicates.

Events: `StatusUpdatedEvent`, `DataUpdatedEvent`, `UserStatusUpdatedEvent`, `UserDataUpdatedEvent`, `BusinessStatusUpdatedEvent`, `BusinessDataUpdatedEvent`, `ActivityCreatedEvent`, `TransactionCreatedEvent`, `TransactionStatusUpdatedEvent`, `TravelRuleStatusUpdatedEvent`, `WorkflowComplianceChangedEvent`, `KybRegistrySearchResolvedEvent` and `UnknownEvent`, all in `Didit\Webhooks\Events`.
Webhook receivers for plain PHP, Laravel and Symfony are in [examples/webhooks/](examples/webhooks/).

## Transactions, wallet screening and Travel Rule

```php
$transaction = $didit->transactions->create([
    'transaction_id' => 'txn-1001',
    'transaction_category' => 'finance',
    'transaction_details' => ['direction' => 'outbound', 'amount' => 950.0, 'currency' => 'EUR'],
    'subject' => ['vendor_data' => 'user-42', 'full_name' => 'Ada Lovelace'],
]);

$didit->transactions->rules->list();
$didit->transactions->travelRule->confirmOwnership($transactionId, [...]);
$didit->walletScreening->screen([...]);
$didit->travelRule->listVasps();
$didit->travelRule->walletAddresses->list();
```

Transaction and Travel Rule status changes arrive as `TransactionCreatedEvent`, `TransactionStatusUpdatedEvent` and `TravelRuleStatusUpdatedEvent` webhooks.

## Authentication

`Didit\Authentication\Authentication` is for the backend of an application that signs its users in with Didit Authentication.
It talks only to your application's issuer (`https://<your-app>.auth.didit.me` or your custom domain) and never uses the API key.

```php
use Didit\Authentication\Authentication;
use Didit\Authentication\TokenVerificationException;

$auth = Authentication::fromPublishableKey('pk_live_...', cache: $psr16Cache);
// or: new Authentication('https://your-app.auth.didit.me')

try {
    $claims = $auth->verifyAccessToken($bearerToken, audience: $applicationId);
    $claims->sub;          // the user
    $claims->vendorData;   // your own user id, when set
} catch (TokenVerificationException $e) {
    // $e->reason: expired, invalid_signature, invalid_issuer, invalid_audience, unknown_key, ...
}
```

- Tokens are verified offline: ES256 only (`none`, HS* and RS* are rejected), header `typ` `at+jwt`, `iss`, `aud` when given, `exp`, `nbf` and `iat` with 60 s leeway. The discovery document and the JWKS are fetched lazily and cached as `Cache-Control` allows; an unknown `kid` refetches the keys at most once a minute. Pass a PSR-16 cache in PHP-FPM so every request does not fetch the keys again.
- `verifyIdToken($token, $nonce, $audience)` adds the nonce check.
- Sign-in with PKCE: `Authentication::createPkce()`, `createState()`, `createNonce()`, then `exchangeCode($code, $codeVerifier, $redirectUri)`.
- `refresh($refreshToken)`: refresh tokens are single-use and reusing one revokes the session, so serialise refreshes for one session.
- `getSession($accessToken)` sees a revocation immediately (offline verification only when the token expires, within 5 minutes); `revokeSession($accessToken)` signs the user out.
- Step-up before sensitive actions: `createStepUp($accessToken, StepUp::ACTION_PAYOUT, '900.00', 'EUR', $returnTo)`, then `retrieveStepUp($accessToken, $id)`; act only when `isApproved()`.

Authentication is in early access and reaches production in the 2026-11-01 Didit release.

## Account management

The `account` resource (sign up, sign in, organizations and applications) uses an account token instead of the API key:

```php
$login = $didit->account->login(['email' => $email, 'password' => $password]);
$account = $didit->withOptions(accountToken: $login->accessToken);
$account->account->organizations->list();
$account->account->applications->list($organizationId);
```

## Language notes

Choices this PHP SDK makes where [DESIGN.md](docs/DESIGN.md) leaves the idiom open:

- Request parameters are arrays described by PHPStan array shapes, not builder objects; date-time fields accept `DateTimeInterface` or an ISO 8601 string. An empty PHP array in a field that is a JSON object is sent as `{}`; for a field that takes any JSON value, pass `new \stdClass()` to send `{}` explicitly.
- Responses are read-only classes; an absent field and a `null` field both read as `null`, and `has('field')` tells them apart.
- Enums are classes of string constants (`Didit\Types\...`), never PHP `enum`s, so unknown values never throw.
- Error classes use the PHP `Exception` suffix (`ApiStatusException` for `APIStatusError`); the Authentication module adds `TokenVerificationException` with a `reason`.
- Per-request options are a `RequestOptions` argument; the raw HTTP response is `rawResponse()` on every result.
- Auto-pagination is `foreach` over a `Page`, plus `each()` and `autoPagingIterator()`.
- PHP has no standard async runtime, so the SDK is synchronous; retries sleep with `usleep`.
- Webhook canonical JSON is serialised by the SDK itself, because `json_encode` differs from Python's `json.dumps` in float format and escaping.
- `verifyParsed()` takes an array or a `stdClass`; arrays from `json_decode($body, true)` cannot tell `{}` from `[]`, so decode into objects or use `verify()` with the raw body.
- ES256 tokens are verified with `ext-openssl`, so the package has no JWT dependency; the PSR-16 cache is optional.

## Supported runtimes and versioning

PHP 8.1 to 8.4, tested on Linux, macOS and Windows.
The SDK follows [semantic versioning](https://semver.org); before 1.0.0, breaking changes bump the minor version.
The API surface is generated from the OpenAPI contract at docs.didit.me and a daily job keeps it current; see [CONTRIBUTING.md](CONTRIBUTING.md).
Security issues: see [SECURITY.md](SECURITY.md).

## License

MIT
