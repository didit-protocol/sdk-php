<?php

declare(strict_types=1);

/*
 * Crypto compliance: screen a wallet address, then read the Travel Rule
 * configuration, the VASP directory and your wallet address book.
 *
 *     DIDIT_API_KEY=... php examples/travel_rule.php [wallet-address] [blockchain]
 *
 * Every call here only reads, except the wallet screening, which is billed on
 * live applications (sandbox applications are not billed). Set
 * DIDIT_TRAVEL_RULE_TRANSACTION_ID to a transaction uuid to also print its
 * Travel Rule transfer.
 */

require __DIR__ . '/../vendor/autoload.php';

use Didit\DiditClient;
use Didit\Exception\DiditException;

$walletAddress = $argv[1] ?? '0x94f1b9b64e2932f6a2db338f616844400cd58e8a';
$blockchain = $argv[2] ?? 'ETH';
$baseUrl = getenv('DIDIT_BASE_URL');
$transactionId = getenv('DIDIT_TRAVEL_RULE_TRANSACTION_ID');

$didit = new DiditClient(baseUrl: false === $baseUrl || '' === $baseUrl ? null : $baseUrl);

try {
    // On-demand risk screening of one address; nothing is stored as a transaction.
    $screening = $didit->walletScreening->screen([
        'wallet_address' => $walletAddress,
        'blockchain' => $blockchain,
        'direction' => 'outbound',
    ]);
    echo "Wallet {$screening->walletAddress} on {$screening->blockchain}\n";
    echo "  risk score {$screening->riskScore} ({$screening->severity}), sanctions hit: "
        . (true === $screening->sanctionsHit ? 'yes' : 'no') . "\n";
    echo "  {$screening->summary}\n";
    foreach ($screening->riskFactors ?? [] as $factor) {
        echo "  risk factor: {$factor->label} ({$factor->category})\n";
    }
    // $didit->walletScreening->screenPdf($screening->toArray()) renders this result as a PDF.

    // Your Travel Rule settings and VASP profile (a disabled default on first read).
    $settings = $didit->travelRule->retrieveSettings();
    echo "\nTravel Rule enabled: " . (true === $settings->isEnabled ? 'yes' : 'no')
        . ", jurisdiction {$settings->jurisdiction}, threshold {$settings->thresholdAmount}\n";

    // Counterparty VASPs you can send transfers to.
    echo "\nVASP directory:\n";
    foreach ($didit->travelRule->listVasps(['limit' => 10]) as $i => $vasp) {
        echo "  {$vasp->name} ({$vasp->jurisdiction}) rails: " . implode(', ', $vasp->reachableRails ?? []) . "\n";
        if ($i >= 9) {
            break;
        }
    }

    // Addresses you registered so inbound transfers resolve to your customers.
    echo "\nWallet address book:\n";
    foreach ($didit->travelRule->walletAddresses->list(['limit' => 50]) as $entry) {
        $verified = true === $entry->isOwnershipVerified ? 'ownership verified' : 'ownership not verified';
        echo "  {$entry->address} ({$entry->chain}) {$entry->holderName}, {$verified}\n";
    }

    if (false !== $transactionId && '' !== $transactionId) {
        // The Travel Rule transfer attached to a monitored transaction.
        $transaction = $didit->transactions->retrieve($transactionId);
        $transfer = $transaction->travelRule;
        echo "\nTransaction {$transaction->txnId}: Travel Rule "
            . (null === $transfer ? 'not applicable' : "{$transfer->status} via {$transfer->protocol}") . "\n";
        // Next steps change state, so they are not run here:
        //   $didit->transactions->travelRule->updateTransfer($id, ['payment_txn_id' => $hash]) finishes it,
        //   $didit->transactions->travelRule->updateTransfer($id, ['action' => 'cancel']) cancels it,
        //   $didit->transactions->travelRule->confirmOwnership($id, ['confirmed' => true]) answers an
        //   ownership check on the beneficiary side.
    }
} catch (DiditException $e) {
    fwrite(\STDERR, 'Didit error: ' . $e->getMessage() . "\n");
    exit(1);
}
