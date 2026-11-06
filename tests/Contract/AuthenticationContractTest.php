<?php

declare(strict_types=1);

namespace Didit\Tests\Contract;

use Didit\Authentication\Authentication;
use Didit\Authentication\StepUp;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * DESIGN section 11: every `server: true` operation in
 * `openapi/authentication.json` has exactly one method of the hand-written
 * Authentication module (section 10), and nothing else exists. Each method
 * sends its operation and reads the document's example response, so a spec
 * sync that adds, removes or renames an Authentication operation fails here
 * until the module follows.
 *
 * @internal
 */
final class AuthenticationContractTest extends TestCase
{
    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * `x-didit-sdk` method or variant => the module's method. The module names
     * the discovery document and key set reads after what they return.
     */
    private const METHODS = [
        'get_openid_configuration' => 'discovery',
        'get_jwks' => 'jwks',
        'exchange_code' => 'exchangeCode',
        'refresh' => 'refresh',
        'get_session' => 'getSession',
        'revoke_session' => 'revokeSession',
        'create_step_up' => 'createStepUp',
        'retrieve_step_up' => 'retrieveStepUp',
    ];

    /** Public methods that never call the issuer: construction, PKCE and offline token verification. */
    private const LOCAL = ['fromPublishableKey', 'createPkce', 'createState', 'createNonce', 'verifyAccessToken', 'verifyIdToken'];

    public function testEveryServerOperationHasExactlyOneMethod(): void
    {
        $documented = array_keys(self::operations());
        sort($documented);
        $mapped = array_keys(self::METHODS);
        sort($mapped);

        self::assertSame($mapped, $documented, 'the server operations of openapi/authentication.json differ from the Authentication module');
    }

    public function testNothingElseExists(): void
    {
        $methods = [];
        foreach ((new \ReflectionClass(Authentication::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isConstructor() && Authentication::class === $method->getDeclaringClass()->getName()) {
                $methods[] = $method->getName();
            }
        }
        $expected = [...array_values(self::METHODS), ...self::LOCAL];
        sort($methods);
        sort($expected);

        self::assertSame($expected, $methods);
    }

    public function testEachMethodSendsItsOperationAndReadsTheDocumentedResponse(): void
    {
        foreach (self::operations() as $name => $operation) {
            $http = self::issuer();
            $auth = new Authentication(self::issuerUrl(), $http, new HttpFactory(), new HttpFactory());

            $result = self::call($auth, $name);

            $request = end($http->requests);
            self::assertInstanceOf(RequestInterface::class, $request, $name);
            self::assertSame($operation['verb'], $request->getMethod(), $name);
            self::assertStringStartsWith(self::issuerUrl() . '/', (string) $request->getUri(), $name);
            self::assertMatchesRegularExpression(self::pattern($operation['path']), $request->getUri()->getPath(), $name);
            if (null !== $operation['discriminator']) {
                parse_str((string) $request->getBody(), $form);
                self::assertSame($operation['discriminator'], $form['grant_type'] ?? null, $name);
            }
            self::assertIsObject($result, $name);
        }
    }

    private static function call(Authentication $auth, string $method): object
    {
        return match ($method) {
            'get_openid_configuration' => $auth->discovery(),
            'get_jwks' => $auth->jwks(),
            'exchange_code' => $auth->exchangeCode('test-code', 'test-code-verifier', 'https://app.example.com/callback'),
            'refresh' => $auth->refresh('test-refresh-token'),
            'get_session' => $auth->getSession('test-access-token'),
            'revoke_session' => $auth->revokeSession('test-access-token'),
            'create_step_up' => $auth->createStepUp('test-access-token', StepUp::ACTION_PAYOUT, '900.00', 'EUR'),
            'retrieve_step_up' => $auth->retrieveStepUp('test-access-token', '94efc2c3-ca84-42fe-900b-6fb61669a7a1'),
        };
    }

    /**
     * Every `server: true` operation and variant, keyed by its `x-didit-sdk` method.
     *
     * @return array<string, array{verb: string, path: string, discriminator: string|null}>
     */
    private static function operations(): array
    {
        $operations = [];
        foreach (self::paths() as $path => $item) {
            foreach (self::HTTP_METHODS as $verb) {
                $operation = $item[$verb] ?? null;
                if (!\is_array($operation)) {
                    continue;
                }
                $key = strtoupper($verb) . ' ' . $path;
                $naming = $operation['x-didit-sdk'] ?? null;
                self::assertIsArray($naming, "{$key} has no x-didit-sdk naming");
                if (true !== ($naming['server'] ?? null)) {
                    continue;
                }
                self::assertSame('authentication', $naming['resource'] ?? null, "{$key} belongs to the authentication module");
                $variants = $naming['variants'] ?? [['method' => $naming['method'] ?? null, 'discriminator' => null]];
                self::assertIsArray($variants, $key);
                foreach ($variants as $variant) {
                    self::assertIsArray($variant, $key);
                    $method = $variant['method'] ?? null;
                    $discriminator = $variant['discriminator'] ?? null;
                    self::assertIsString($method, $key);
                    self::assertTrue(null === $discriminator || \is_string($discriminator), $key);
                    self::assertArrayNotHasKey($method, $operations, "{$method} names two operations");
                    $operations[$method] = ['verb' => strtoupper($verb), 'path' => (string) $path, 'discriminator' => $discriminator];
                }
            }
        }

        return $operations;
    }

    /**
     * A PSR-18 client that answers every operation of the document with its
     * first success response and example.
     *
     * @return ClientInterface&object{requests: list<RequestInterface>}
     */
    private static function issuer(): ClientInterface
    {
        $routes = [];
        foreach (self::paths() as $path => $item) {
            foreach (self::HTTP_METHODS as $verb) {
                $operation = $item[$verb] ?? null;
                if (\is_array($operation)) {
                    $routes[] = ['verb' => strtoupper($verb), 'pattern' => self::pattern((string) $path)] + self::example($operation);
                }
            }
        }

        return new class($routes) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            /**
             * @param list<array{verb: string, pattern: string, status: int, body: string|null}> $routes
             */
            public function __construct(private readonly array $routes)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                foreach ($this->routes as $route) {
                    if ($route['verb'] === $request->getMethod() && 1 === preg_match($route['pattern'], $request->getUri()->getPath())) {
                        return new Response($route['status'], null === $route['body'] ? [] : ['Content-Type' => 'application/json'], $route['body']);
                    }
                }

                throw new \LogicException("openapi/authentication.json has no operation {$request->getMethod()} {$request->getUri()}");
            }
        };
    }

    /**
     * @param array<mixed> $operation
     *
     * @return array{status: int, body: string|null}
     */
    private static function example(array $operation): array
    {
        $responses = $operation['responses'] ?? null;
        self::assertIsArray($responses);
        foreach ($responses as $status => $response) {
            if (!str_starts_with((string) $status, '2')) {
                continue;
            }
            self::assertIsArray($response);
            $media = $response['content']['application/json'] ?? null;
            if (!\is_array($media)) {
                return ['status' => (int) $status, 'body' => null];
            }
            $examples = $media['examples'] ?? null;
            $example = \is_array($examples) && [] !== $examples ? (reset($examples)['value'] ?? null) : ($media['example'] ?? null);
            self::assertNotNull($example, "response {$status} has an example");

            return ['status' => (int) $status, 'body' => json_encode($example, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)];
        }
        self::fail('an operation without a success response');
    }

    private static function pattern(string $path): string
    {
        $quoted = preg_quote($path, '#');

        return '#^' . preg_replace('#\\\{[^/]+?\\\}#', '[^/]+', $quoted) . '$#';
    }

    /**
     * The issuer of the document's server URL, `https://{issuer}` with its default.
     */
    private static function issuerUrl(): string
    {
        $default = self::document()['servers'][0]['variables']['issuer']['default'] ?? null;
        self::assertIsString($default);

        return 'https://' . $default;
    }

    /**
     * @return array<array<mixed>>
     */
    private static function paths(): array
    {
        $paths = self::document()['paths'] ?? null;
        self::assertIsArray($paths);
        foreach ($paths as $item) {
            self::assertIsArray($item);
        }

        /** @var array<array<mixed>> $paths */
        return $paths;
    }

    /**
     * @return array<mixed>
     */
    private static function document(): array
    {
        $contents = file_get_contents(\dirname(__DIR__, 2) . '/openapi/authentication.json');
        self::assertIsString($contents, 'openapi/authentication.json is readable');
        $data = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
