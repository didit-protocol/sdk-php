<?php

declare(strict_types=1);

namespace Didit\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Runs PHP code in a child process with the autoloader, for behaviour that
 * depends on process state (STDERR, environment, which classes are loaded).
 */
final class PhpProcess
{
    private function __construct()
    {
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{stdout: string, stderr: string}
     */
    public static function run(string $code, array $env = []): array
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = 'require ' . var_export($autoload, true) . ";\n" . $code;
        $environment = getenv();
        unset($environment['DIDIT_LOG'], $environment['DIDIT_API_KEY']);
        $process = proc_open(
            [\PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + $environment,
        );
        Assert::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        Assert::assertSame(0, proc_close($process), "child process failed:\n{$stdout}\n{$stderr}");
        Assert::assertIsString($stdout);
        Assert::assertIsString($stderr);

        return ['stdout' => $stdout, 'stderr' => $stderr];
    }
}
