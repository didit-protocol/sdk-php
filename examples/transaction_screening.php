<?php

declare(strict_types=1);

/*
 * Transaction monitoring: submit a payment for AML and risk screening, read
 * the full record back and list recent transactions.
 *
 *     DIDIT_API_KEY=... php examples/transaction_screening.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Didit\DiditClient;
use Didit\Exception\DiditException;

$baseUrl = getenv('DIDIT_BASE_URL');

$didit = new DiditClient(baseUrl: false === $baseUrl || '' === $baseUrl ? null : $baseUrl);

try {
    // Your rules run inline: `status` comes back APPROVED, IN_REVIEW or DECLINED.
    $transaction = $didit->transactions->create([
        // Your id, unique per application: it is also the idempotency key.
        'transaction_id' => 'txn-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)),
        'transaction_category' => 'finance',
        'transaction_details' => [
            'direction' => 'outbound',
            'amount' => 1500,
            'currency' => 'EUR',
            'action_type' => 'withdrawal',
        ],
        'subject' => [
            'entity_type' => 'individual',
            'vendor_data' => 'user-123',
            'full_name' => 'Jane Example',
            'date_of_birth' => '1990-05-17',
            'address' => ['country' => 'ESP', 'town' => 'Madrid'],
        ],
        'counterparty' => [
            'entity_type' => 'individual',
            'full_name' => 'John Example',
            'payment_method' => ['method_type' => 'bank_account', 'issuing_country' => 'DEU'],
        ],
    ]);
    echo "Created transaction {$transaction->txnId} ({$transaction->uuid})\n";
    echo "  status: {$transaction->status}, score: {$transaction->score}, severity: {$transaction->severity}\n";

    // Read the full record with Didit's uuid (not your transaction_id).
    $uuid = $transaction->uuid ?? throw new UnexpectedValueException('The response has no uuid.');
    $detail = $didit->transactions->retrieve($uuid);
    echo "  {$detail->direction} {$detail->amount} {$detail->currency}: "
        . \count($detail->alerts ?? []) . ' alerts, ' . \count($detail->ruleRuns ?? []) . " rule runs\n";

    // The list endpoint pages with limit/offset and has no server-side filters,
    // so filter while iterating.
    echo "\nTransactions waiting for review:\n";
    $seen = 0;
    foreach ($didit->transactions->list(['limit' => 50]) as $item) {
        if ('IN_REVIEW' === $item->status) {
            echo "  {$item->txnId}: {$item->amount} {$item->currency}, {$item->subjectName} -> {$item->counterpartyName}, score {$item->score}\n";
        }
        if (++$seen >= 200) {
            break;
        }
    }
} catch (DiditException $e) {
    fwrite(\STDERR, 'Didit error: ' . $e->getMessage() . "\n");
    exit(1);
}
