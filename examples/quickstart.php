<?php

declare(strict_types=1);

/*
 * Quickstart: create a verification session, read its decision and verify a webhook.
 *
 *     DIDIT_API_KEY=... DIDIT_WORKFLOW_ID=... php examples/quickstart.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Didit\DiditClient;
use Didit\Exception\DiditException;
use Didit\Webhooks\Events\SessionEvent;

$workflowId = getenv('DIDIT_WORKFLOW_ID');
if (false === $workflowId || '' === $workflowId) {
    fwrite(\STDERR, "Set DIDIT_WORKFLOW_ID to the id of a workflow from the Didit Console.\n");
    exit(1);
}
$baseUrl = getenv('DIDIT_BASE_URL');

// The API key comes from DIDIT_API_KEY. DIDIT_BASE_URL is only needed for a local mock server.
$didit = new DiditClient(baseUrl: false === $baseUrl || '' === $baseUrl ? null : $baseUrl);

try {
    // 1. Create a session and send your user to its URL.
    $session = $didit->sessions->create([
        'workflow_id' => $workflowId,
        'vendor_data' => 'user-123',
    ]);
    $sessionId = $session->sessionId ?? throw new UnexpectedValueException('The response has no session_id.');
    echo "Created session {$sessionId} ({$session->status})\n";
    echo "Verification URL: {$session->url}\n";

    // 2. Read the decision once the user has finished (your callback URL or a webhook tells you when).
    $decision = $didit->sessions->retrieveDecision($sessionId);
    echo "Decision: {$decision->status}\n";

    // 3. Verify a webhook. This signs a sample delivery the way Didit does; in your
    //    endpoint, pass the raw request body and the request headers instead.
    $secret = getenv('DIDIT_WEBHOOK_SECRET');
    $secret = false === $secret || '' === $secret ? 'whsec_test_quickstart' : $secret;
    $now = time();
    $payload = [
        'webhook_type' => 'status.updated',
        'event_id' => '6f1c2d3e-0000-4000-8000-000000000001',
        'session_id' => $sessionId,
        'status' => 'Approved',
        'workflow_id' => $workflowId,
        'vendor_data' => 'user-123',
        'environment' => 'sandbox',
        'created_at' => $now,
        'timestamp' => $now,
    ];
    ksort($payload, \SORT_STRING); // Didit sends sorted keys and no whitespace
    $body = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    $headers = [
        'X-Signature' => hash_hmac('sha256', $body, $secret),
        'X-Timestamp' => (string) $now,
    ];

    $event = $didit->webhooks->verify($body, $headers, $secret);
    echo 'Webhook verified: ' . $event::class . "\n";
    if ($event instanceof SessionEvent) {
        echo "  {$event->webhookType}: session {$event->sessionId} is {$event->status} (vendor_data {$event->vendorData})\n";
    }
} catch (DiditException $e) {
    fwrite(\STDERR, 'Didit error: ' . $e->getMessage() . "\n");
    exit(1);
}
