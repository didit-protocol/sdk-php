<?php

declare(strict_types=1);

namespace App\Controller;

use Didit\Exception\WebhookVerificationException;
use Didit\Webhooks\Event;
use Didit\Webhooks\Events;
use Didit\Webhooks\Webhook;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives Didit webhooks (see README.md).
 */
final class DiditWebhookController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(DIDIT_WEBHOOK_SECRET)%')]
        private readonly string $webhookSecret,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/webhooks/didit', name: 'didit_webhook', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            // The raw body, exactly as received: not $request->toArray() or $request->getPayload().
            $event = Webhook::verify($request->getContent(), $request->headers->all(), $this->webhookSecret);
        } catch (WebhookVerificationException $e) {
            $this->logger->warning('Rejected a Didit webhook', ['reason' => $e->reason]);
            $signatureProblem = \in_array($e->reason, [
                WebhookVerificationException::MISSING_SIGNATURE,
                WebhookVerificationException::INVALID_SIGNATURE,
            ], true);

            return $this->json(['error' => $e->reason], $signatureProblem ? 401 : 400);
        }

        $this->handle($event);

        return $this->json(['received' => true]);
    }

    private function handle(Event $event): void
    {
        // Didit retries a delivery until it gets a 2xx, with the same event id: skip
        // ids you already processed. Send slow work to Messenger.
        if ($event instanceof Events\StatusUpdatedEvent) {
            $this->logger->info('Didit session status', [
                'session_id' => $event->sessionId,
                'vendor_data' => $event->vendorData,
                'status' => $event->status,
            ]);
        } elseif ($event instanceof Events\TransactionEvent) {
            $this->logger->info('Didit transaction', ['txn_id' => $event->txnId, 'status' => $event->status]);
        } else {
            // Every other type, including ones this SDK version does not know: $event->toArray().
            $this->logger->info('Didit webhook', ['webhook_type' => $event->webhookType, 'event_id' => $event->eventId]);
        }
    }
}
