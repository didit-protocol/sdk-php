<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Didit\Exception\WebhookVerificationException;
use Didit\Webhooks\Event;
use Didit\Webhooks\Events;
use Didit\Webhooks\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Didit webhooks. The route is in routes/api.php and the secret in
 * config/services.php (see README.md).
 */
final class DiditWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.didit.webhook_secret');
        if (!\is_string($secret) || '' === $secret) {
            return response()->json(['error' => 'services.didit.webhook_secret is not set'], 500);
        }

        try {
            // The raw body, exactly as received: not $request->all() or $request->json().
            $event = Webhook::verify($request->getContent(), $request->headers->all(), $secret);
        } catch (WebhookVerificationException $e) {
            Log::warning('Rejected a Didit webhook', ['reason' => $e->reason]);
            $signatureProblem = \in_array($e->reason, [
                WebhookVerificationException::MISSING_SIGNATURE,
                WebhookVerificationException::INVALID_SIGNATURE,
            ], true);

            return response()->json(['error' => $e->reason], $signatureProblem ? 401 : 400);
        }

        $this->handle($event);

        return response()->json(['received' => true]);
    }

    private function handle(Event $event): void
    {
        // Didit retries a delivery until it gets a 2xx, with the same event id: skip
        // ids you already processed. Dispatch a queued job for slow work.
        if ($event instanceof Events\StatusUpdatedEvent) {
            // For example: User::where('id', $event->vendorData)->update(['kyc_status' => $event->status]);
            Log::info('Didit session status', [
                'session_id' => $event->sessionId,
                'vendor_data' => $event->vendorData,
                'status' => $event->status,
            ]);
        } elseif ($event instanceof Events\TransactionEvent) {
            Log::info('Didit transaction', ['txn_id' => $event->txnId, 'status' => $event->status]);
        } else {
            // Every other type, including ones this SDK version does not know: $event->toArray().
            Log::info('Didit webhook', ['webhook_type' => $event->webhookType, 'event_id' => $event->eventId]);
        }
    }
}
