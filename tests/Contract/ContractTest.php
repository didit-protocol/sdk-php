<?php

declare(strict_types=1);

namespace Didit\Tests\Contract;

use Didit\Core\BaseResource;
use Didit\DiditClient;
use Didit\Webhooks\VerifiesWebhooks;
use PHPUnit\Framework\TestCase;

/**
 * DESIGN section 11: every `server: true` operation in `openapi/` has exactly
 * one SDK method, and nothing else exists.
 *
 * @internal
 */
final class ContractTest extends TestCase
{
    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete'];
    private const RESOURCE_NAMESPACE = 'Didit\Resources\\';
    private const SCOPE_PARAMETERS = ['organizationid', 'applicationid', 'organization_id', 'application_id', 'orgid', 'appid'];

    /** @var list<array{key: string, resource: string, method: string, scope: string|null, deprecated: bool}>|null */
    private static ?array $operations = null;

    /** @var array<string, \ReflectionMethod>|null */
    private static ?array $methods = null;

    public function testEveryServerOperationHasExactlyOneMethodAndNothingElseExists(): void
    {
        $expected = [];
        $duplicates = [];
        foreach (self::serverOperations() as $operation) {
            $name = self::sdkName($operation['resource'], $operation['method']);
            if (isset($expected[$name])) {
                $duplicates[] = "{$name} ({$expected[$name]} and {$operation['key']})";
            }
            $expected[$name] = $operation['key'];
        }
        $actual = self::sdkMethods();

        self::assertSame([], $duplicates, 'two operations share one SDK name');
        $missing = array_diff_key($expected, $actual);
        $extra = array_diff_key($actual, $expected);
        $report = '';
        foreach ($missing as $name => $key) {
            $report .= "\n  missing: {$name} for {$key}";
        }
        foreach ($extra as $name => $method) {
            $report .= "\n  extra:   {$name} ({$method->class}::{$method->name}())";
        }
        self::assertSame('', $report, 'The SDK surface differs from the server operations in openapi/:' . $report);
        self::assertCount(\count($expected), $actual);
    }

    /**
     * Counted from openapi/, so a spec sync that adds or removes operations
     * keeps passing (version 1 of DESIGN section 4 had 248 + 9).
     */
    public function testOperationCountsMatchTheSpec(): void
    {
        $verification = 0;
        $account = 0;
        foreach (self::serverOperations() as $operation) {
            if ($operation['deprecated']) {
                continue;
            }
            str_starts_with($operation['key'], 'account ') ? ++$account : ++$verification;
        }
        $accountOperations = 0;
        $paths = self::json('account.json')['paths'] ?? [];
        self::assertIsArray($paths);
        foreach ($paths as $item) {
            self::assertIsArray($item);
            $accountOperations += \count(array_intersect(array_keys($item), self::HTTP_METHODS));
        }

        self::assertGreaterThan(0, $verification);
        self::assertSame($accountOperations, $account, 'every account.json operation is a server operation');
        self::assertCount($verification + $account + self::deprecatedCount(), self::sdkMethods(), 'one method per server operation, plus deprecated ones');
    }

    public function testEveryResourceSegmentIsAProperty(): void
    {
        $problems = [];
        foreach (self::serverOperations() as $operation) {
            $class = new \ReflectionClass(DiditClient::class);
            $path = '';
            foreach (explode('.', $operation['resource']) as $segment) {
                $property = self::camel($segment);
                $path .= ('' === $path ? '' : '.') . $segment;
                if (!$class->hasProperty($property)) {
                    $problems[$path] = "{$path}: {$class->getName()} has no \${$property}";
                    continue 2;
                }
                $reflection = $class->getProperty($property);
                $type = $reflection->getType();
                if (!$reflection->isPublic() || !$reflection->isReadOnly() || !$type instanceof \ReflectionNamedType || !str_starts_with($type->getName(), self::RESOURCE_NAMESPACE)) {
                    $problems[$path] = "{$path}: {$class->getName()}::\${$property} is not a public readonly resource";
                    continue 2;
                }
                $class = self::reflect($type->getName());
            }
        }

        self::assertSame([], array_values($problems));
    }

    public function testApplicationScopedMethodsTakeNoScopeIds(): void
    {
        $methods = self::sdkMethods();
        $scoped = 0;
        $problems = [];
        foreach (self::serverOperations() as $operation) {
            if ('application' !== $operation['scope']) {
                continue;
            }
            ++$scoped;
            $method = $methods[self::sdkName($operation['resource'], $operation['method'])] ?? null;
            if (null === $method) {
                continue; // reported by the surface test
            }
            foreach ($method->getParameters() as $parameter) {
                if (\in_array(strtolower($parameter->getName()), self::SCOPE_PARAMETERS, true)) {
                    $problems[] = "{$method->class}::{$method->name}() takes \${$parameter->getName()}";
                }
            }
        }

        self::assertGreaterThan(0, $scoped, 'the spec has application-scoped operations');
        self::assertSame([], $problems);
    }

    public function testCamelMatchesTheGenerator(): void
    {
        self::assertSame('sessionId', self::camel('session_id'));
        self::assertSame('idVerification', self::camel('id_verification'));
        self::assertSame('url', self::camel('URL'));
        self::assertSame('apiKey', self::camel('APIKey'));
        self::assertSame('retrieve2fa', self::camel('retrieve_2fa'));
        self::assertSame('kyb', self::camel('kyb'));
    }

    /**
     * Every `server: true` operation, keyed like the overlay, plus the
     * deprecated operations that are still exposed.
     *
     * @return list<array{key: string, resource: string, method: string, scope: string|null, deprecated: bool}>
     */
    private static function serverOperations(): array
    {
        if (null !== self::$operations) {
            return self::$operations;
        }
        $overlay = self::json('overlay.json')['operations'] ?? [];
        self::assertIsArray($overlay);

        $operations = [];
        $present = [];
        foreach (['' => 'verification.json', 'account ' => 'account.json'] as $prefix => $file) {
            $paths = self::json($file)['paths'] ?? [];
            self::assertIsArray($paths);
            foreach ($paths as $path => $item) {
                self::assertIsArray($item);
                foreach (self::HTTP_METHODS as $httpMethod) {
                    $operation = $item[$httpMethod] ?? null;
                    if (!\is_array($operation)) {
                        continue;
                    }
                    $key = $prefix . strtoupper($httpMethod) . ' ' . $path;
                    $present[$key] = true;
                    $naming = $operation['x-didit-sdk'] ?? $overlay[$key] ?? null;
                    self::assertIsArray($naming, "{$key} has no x-didit-sdk naming");
                    $entry = self::entry($key, $naming, false);
                    if (null !== $entry) {
                        $operations[] = $entry;
                        $present[$key] = $entry['resource'] . '.' . $entry['method'];
                    }
                }
            }
        }

        foreach (self::deprecatedOperations() as $deprecated) {
            $key = $deprecated['key'] ?? null;
            $naming = $deprecated['x-didit-sdk'] ?? null;
            self::assertIsString($key);
            self::assertIsArray($naming, "{$key} has no x-didit-sdk naming");
            $entry = self::entry($key, $naming, true);
            if (null === $entry) {
                continue;
            }
            if (true === ($deprecated['alias'] ?? false)) {
                // The previous name of a renamed operation, while the operation still exists under its new name.
                $current = $present[$key] ?? null;
                if (\is_string($current) && $entry['resource'] . '.' . $entry['method'] !== $current) {
                    $operations[] = $entry;
                }
                continue;
            }
            if (!\is_string($present[$key] ?? null)) {
                // Removed upstream, or no longer a server operation: still exposed as deprecated.
                $operations[] = $entry;
            }
        }

        return self::$operations = $operations;
    }

    /**
     * @param array<mixed> $naming
     *
     * @return array{key: string, resource: string, method: string, scope: string|null, deprecated: bool}|null
     */
    private static function entry(string $key, array $naming, bool $deprecated): ?array
    {
        if (true !== ($naming['server'] ?? null)) {
            return null;
        }
        $resource = $naming['resource'] ?? null;
        $method = $naming['method'] ?? null;
        $scope = $naming['scope'] ?? null;
        self::assertIsString($resource, $key);
        self::assertIsString($method, $key);
        self::assertTrue(null === $scope || \is_string($scope), $key);

        return ['key' => $key, 'resource' => $resource, 'method' => $method, 'scope' => $scope, 'deprecated' => $deprecated];
    }

    /**
     * @return list<array<mixed>>
     */
    private static function deprecatedOperations(): array
    {
        if (!is_file(self::openapiPath('deprecated-operations.json'))) {
            return [];
        }
        $entries = self::json('deprecated-operations.json')['operations'] ?? [];
        self::assertIsArray($entries);
        $out = [];
        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            $out[] = $entry;
        }

        return $out;
    }

    private static function deprecatedCount(): int
    {
        return \count(array_filter(self::serverOperations(), static fn (array $operation): bool => $operation['deprecated']));
    }

    /**
     * Public methods of every resource reachable from DiditClient, keyed
     * `property.property.method`.
     *
     * @return array<string, \ReflectionMethod>
     */
    private static function sdkMethods(): array
    {
        if (null === self::$methods) {
            $methods = [];
            self::collect(new \ReflectionClass(DiditClient::class), '', $methods, []);
            self::$methods = $methods;
        }

        return self::$methods;
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param array<string, \ReflectionMethod> $methods
     * @param list<string> $visiting
     */
    private static function collect(\ReflectionClass $class, string $prefix, array &$methods, array $visiting): void
    {
        self::assertNotContains($class->getName(), $visiting, "resource cycle at {$prefix}");
        $visiting[] = $class->getName();

        foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            if (!$type instanceof \ReflectionNamedType || !str_starts_with($type->getName(), self::RESOURCE_NAMESPACE)) {
                continue;
            }
            self::assertTrue($property->isReadOnly(), "{$class->getName()}::\${$property->getName()} is readonly");
            $child = self::reflect($type->getName());
            self::assertTrue($child->isSubclassOf(BaseResource::class), "{$child->getName()} is a resource");
            self::collect($child, $prefix . $property->getName() . '.', $methods, $visiting);
        }

        if (DiditClient::class === $class->getName()) {
            return;
        }
        $traitMethods = [];
        if (\in_array(VerifiesWebhooks::class, $class->getTraitNames(), true)) {
            foreach ((new \ReflectionClass(VerifiesWebhooks::class))->getMethods() as $method) {
                $traitMethods[] = $method->getName();
            }
        }
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()
                || $method->isConstructor()
                || \in_array($method->getName(), $traitMethods, true)) {
                continue;
            }
            $methods[$prefix . $method->getName()] = $method;
        }
    }

    /**
     * @return \ReflectionClass<object>
     */
    private static function reflect(string $class): \ReflectionClass
    {
        if (!class_exists($class)) {
            self::fail("class {$class} does not exist");
        }

        return new \ReflectionClass($class);
    }

    private static function sdkName(string $resource, string $method): string
    {
        return implode('.', array_map(self::camel(...), explode('.', $resource))) . '.' . self::camel($method);
    }

    /**
     * PHP port of `camel()` in codegen/didit_codegen/naming.py.
     */
    private static function camel(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, \PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($words);
        $pascal = implode('', array_map(static fn (string $word): string => ucfirst($word), $words));
        if ('' === $pascal) {
            return $pascal;
        }
        $run = 0;
        $length = \strlen($pascal);
        while ($run < $length && 1 === preg_match('/^[A-Z]$/', $pascal[$run])) {
            ++$run;
        }
        if ($run === $length) {
            return strtolower($pascal);
        }
        if ($run > 1) {
            return strtolower(substr($pascal, 0, $run - 1)) . substr($pascal, $run - 1);
        }

        return lcfirst($pascal);
    }

    /**
     * @return array<mixed>
     */
    private static function json(string $file): array
    {
        $contents = file_get_contents(self::openapiPath($file));
        self::assertIsString($contents, "openapi/{$file} is readable");
        $data = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private static function openapiPath(string $file): string
    {
        return \dirname(__DIR__, 2) . '/openapi/' . $file;
    }
}
