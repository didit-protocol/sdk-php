<?php

declare(strict_types=1);

/*
 * KYC session: create a session linked to your user, list sessions page after
 * page, read a decision and save the PDF report.
 *
 *     DIDIT_API_KEY=... DIDIT_WORKFLOW_ID=... php examples/kyc_session.php [session-id]
 *
 * The decision and the PDF are read for the given session id, or for the new
 * session, which has no result until a user completes it.
 */

require __DIR__ . '/../vendor/autoload.php';

use Didit\DiditClient;
use Didit\Exception\ApiStatusException;
use Didit\Exception\DiditException;

$env = static function (string $name): ?string {
    $value = getenv($name);

    return false === $value || '' === $value ? null : $value;
};

$workflowId = $env('DIDIT_WORKFLOW_ID');
if (null === $workflowId) {
    fwrite(\STDERR, "Set DIDIT_WORKFLOW_ID to the id of a workflow from the Didit Console.\n");
    exit(1);
}

$didit = new DiditClient(baseUrl: $env('DIDIT_BASE_URL'));

try {
    $session = $didit->sessions->create([
        'workflow_id' => $workflowId,
        // Your id for the user: it comes back on the decision, in webhooks and in the Console.
        'vendor_data' => 'user-123',
        // Any JSON you want stored with the session; never shown to the user.
        'metadata' => ['plan' => 'premium', 'signup_source' => 'web'],
        // Where the user lands when done; Didit appends ?verificationSessionId=...&status=...
        'callback' => 'https://example.com/kyc/done',
        'language' => 'en',
    ]);
    echo "Created session #{$session->sessionNumber} {$session->sessionId}\n";
    echo "  status: {$session->status}\n";
    echo "  url:    {$session->url}\n";

    // Iterating a page walks every following page too; `limit` is the page size.
    $sessions = $didit->sessions->list(['limit' => 20]);
    echo "\nSessions in this application: {$sessions->count}\n";
    $shown = 0;
    foreach ($sessions as $item) {
        echo "  #{$item->sessionNumber} {$item->sessionId} {$item->status} vendor_data={$item->vendorData}\n";
        if (++$shown >= 50) {
            break; // stop whenever you have enough
        }
    }

    $sessionId = $argv[1] ?? $session->sessionId ?? throw new UnexpectedValueException('The response has no session_id.');

    $decision = $didit->sessions->retrieveDecision($sessionId);
    echo "\nDecision for {$sessionId}: {$decision->status}\n";
    echo '  features: ' . implode(', ', $decision->features ?? []) . "\n";
    foreach ($decision->idVerifications ?? [] as $check) {
        echo "  ID verification: {$check->status} ({$check->documentType})\n";
    }
    foreach ($decision->livenessChecks ?? [] as $check) {
        echo "  liveness: {$check->status} (score {$check->score})\n";
    }
    foreach ($decision->faceMatches ?? [] as $check) {
        echo "  face match: {$check->status} (score {$check->score})\n";
    }
    foreach ($decision->amlScreenings ?? [] as $check) {
        echo "  AML: {$check->status} ({$check->totalHits} hits)\n";
    }

    try {
        $pdf = $didit->sessions->generatePdf($sessionId);
        $path = sys_get_temp_dir() . '/' . ($pdf->filename() ?? "didit-session-{$sessionId}.pdf");
        $pdf->saveTo($path);
        echo "\nSaved the PDF report to {$path}\n";
    } catch (ApiStatusException $e) {
        // A report exists once the session has a result.
        echo "\nNo PDF report: {$e->getMessage()}\n";
    }
} catch (DiditException $e) {
    fwrite(\STDERR, 'Didit error: ' . $e->getMessage() . "\n");
    exit(1);
}
