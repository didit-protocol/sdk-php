<?php

declare(strict_types=1);

/*
 * A Didit webhook receiver in plain PHP, with no framework.
 *
 *     DIDIT_WEBHOOK_SECRET=whsec_test_... php -S 127.0.0.1:8080 examples/webhooks/plain_php.php
 *
 * Then point a webhook destination at it (in development through a tunnel).
 * Under PHP-FPM, use the same code as the endpoint's script.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Didit\Exception\ConfigurationException;
use Didit\Exception\WebhookVerificationException;
use Didit\Webhooks\Event;
use Didit\Webhooks\Events;
use Didit\Webhooks\EventType;
use Didit\Webhooks\Webhook;

/**
 * @param array<string, mixed> $body
 */
function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    exit;
}

/**
 * The request headers from getallheaders() (php -S, PHP-FPM, Apache), names
 * in any case.
 *
 * @return array<string, string>
 */
function requestHeaders(): array
{
    $all = getallheaders();
    $headers = [];
    foreach (is_array($all) ? $all : [] as $name => $value) {
        if (is_string($name) && is_string($value)) {
            $headers[$name] = $value;
        }
    }

    return $headers;
}

/**
 * Your business logic. Didit retries a delivery until it gets a 2xx, so make
 * this idempotent: `$event->eventId` is the same on every retry.
 */
function handle(Event $event): string
{
    if ($event instanceof Events\SessionEvent) {
        // status.updated and data.updated, for user (KYC) and business (KYB) sessions.
        return match ($event->webhookType) {
            EventType::STATUS_UPDATED => "session {$event->sessionId} of {$event->vendorData} is {$event->status}",
            default => "session {$event->sessionId} data changed",
        };
    }
    if ($event instanceof Events\TransactionEvent) {
        return "transaction {$event->txnId} is {$event->status} (score {$event->score}, {$event->severity})";
    }
    if ($event instanceof Events\TravelRuleStatusUpdatedEvent) {
        return "Travel Rule for transaction {$event->txnId} is {$event->travelRuleStatus} on {$event->rail}";
    }
    if ($event instanceof Events\UserEvent) {
        return "user {$event->vendorUserId} is {$event->status}";
    }
    if ($event instanceof Events\BusinessEvent) {
        return "business {$event->vendorBusinessId} is {$event->status}";
    }

    // Other and future types (Events\UnknownEvent): every field is in $event->toArray().
    return "{$event->webhookType} received";
}

if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? null)) {
    respond(405, ['error' => 'method_not_allowed']);
}

$secret = getenv('DIDIT_WEBHOOK_SECRET');
if (false === $secret || '' === $secret) {
    respond(500, ['error' => 'DIDIT_WEBHOOK_SECRET is not set']);
}
$body = file_get_contents('php://input');
if (false === $body) {
    respond(400, ['error' => 'unreadable_body']);
}

try {
    // The raw body, exactly as received: do not decode and re-encode it first.
    // During a secret rotation, pass both secrets: [$newSecret, $oldSecret].
    $event = Webhook::verify($body, requestHeaders(), $secret);
} catch (WebhookVerificationException $e) {
    $signatureProblem = in_array($e->reason, [
        WebhookVerificationException::MISSING_SIGNATURE,
        WebhookVerificationException::INVALID_SIGNATURE,
    ], true);
    error_log("Rejected Didit webhook: {$e->reason}");
    respond($signatureProblem ? 401 : 400, ['error' => $e->reason]);
} catch (ConfigurationException $e) {
    respond(500, ['error' => $e->getMessage()]);
}

$summary = handle($event);
error_log('Didit webhook ' . $event::class . ": {$summary}");

respond(200, ['received' => true, 'event_id' => $event->eventId, 'webhook_type' => $event->webhookType]);
