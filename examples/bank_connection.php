<?php

declare(strict_types=1);

/*
 * Bank data: create a Bank Link, wait until its connection is ready, then read
 * the accounts, balances and recent transactions.
 *
 *     DIDIT_API_KEY=... php examples/bank_connection.php
 *
 * A server normally waits for the `bank.connection.created` webhook instead of
 * polling; this script polls so it runs without a webhook receiver:
 *
 *     $event = $didit->webhooks->verify($rawBody, $headers, $secret);
 *     if ($event instanceof Didit\Webhooks\Events\BankConnectionCreatedEvent) {
 *         $connectionId = $event->connectionId;
 *     }
 */

require __DIR__ . '/../vendor/autoload.php';

use Didit\DiditClient;
use Didit\Exception\DiditException;

$baseUrl = getenv('DIDIT_BASE_URL');
$didit = new DiditClient(baseUrl: false === $baseUrl || '' === $baseUrl ? null : $baseUrl);

/**
 * @param callable(): bool $ready
 */
function waitFor(callable $ready, int $timeoutSeconds, string $what): void
{
    $deadline = time() + $timeoutSeconds;
    while (!$ready()) {
        if (time() >= $deadline) {
            throw new RuntimeException("Timed out waiting for {$what}.");
        }
        sleep(5);
    }
}

try {
    $link = $didit->bank->createLink([
        'access' => 'ongoing',
        'data' => ['accounts', 'balances', 'transactions'],
        // Your stable id for the person; no email or name is needed.
        'end_user' => ['reference' => 'customer-42'],
    ]);

    $linkId = $link->id ?? throw new UnexpectedValueException('The link has no id.');
    echo "Created bank link {$linkId}\n";
    echo "Open this URL to connect the bank: {$link->url}\n";

    // In production, let the bank.connection.created webhook wake you instead.
    waitFor(static function () use ($didit, $linkId): bool {
        $current = $didit->bank->getLink($linkId);
        if ('completed' === $current->status) {
            return true;
        }
        if (in_array($current->status, ['cancelled', 'expired', 'failed'], true)) {
            throw new RuntimeException("The link ended as {$current->status}: {$current->error?->code}");
        }

        return false;
    }, 600, 'the bank link to complete');

    $link = $didit->bank->getLink($linkId);
    $connectionId = $link->connectionId ?? throw new UnexpectedValueException('The completed link has no connection_id.');

    waitFor(static function () use ($didit, $connectionId): bool {
        $connection = $didit->bank->getConnection($connectionId);

        return true === $connection->sync?->initialComplete;
    }, 300, 'the first bank sync');

    $connection = $didit->bank->getConnection($connectionId);
    echo "Connection {$connectionId} is {$connection->status}";
    $expiresAt = $connection->consent?->expiresAt;
    if (null !== $expiresAt) {
        echo ", consent until {$expiresAt->format('Y-m-d')}";
    }
    echo "\n";

    $cursor = null;
    do {
        $page = $didit->bank->listAccounts($connectionId, null === $cursor ? ['limit' => 50] : ['limit' => 50, 'cursor' => $cursor]);
        foreach ($page->items ?? [] as $account) {
            echo "  {$account->name} ({$account->type}, {$account->currency}): {$account->status}\n";

            $balances = $didit->bank->getBalances($account->id ?? '');
            foreach ($balances->balances ?? [] as $balance) {
                echo "    {$balance->type}: {$balance->amount} {$balance->currency}\n";
            }

            $transactions = $didit->bank->listTransactions($account->id ?? '', [
                'date_from' => (new DateTimeImmutable('-30 days'))->format('Y-m-d'),
                'limit' => 5,
            ]);
            foreach ($transactions->items ?? [] as $transaction) {
                echo "    {$transaction->bookingDate}: {$transaction->amount} {$transaction->currency} {$transaction->description}\n";
            }
        }
        $cursor = $page->nextCursor;
    } while (null !== $cursor);
} catch (DiditException|RuntimeException $e) {
    fwrite(\STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
