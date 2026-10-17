<?php

declare(strict_types=1);

namespace Didit\Core;

use Psr\Log\AbstractLogger;

/**
 * The logger used when `DIDIT_LOG=debug` is set and no logger is passed.
 *
 * @internal
 */
final class StderrLogger extends AbstractLogger
{
    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $line = \sprintf('[didit] %s %s', \is_string($level) ? $level : 'debug', (string) $message);
        $stream = \defined('STDERR') ? \STDERR : fopen('php://stderr', 'w');
        if (false !== $stream) {
            fwrite($stream, $line . \PHP_EOL);
        }
    }
}
