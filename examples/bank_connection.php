<?php

declare(strict_types=1);

/*
 * Single-customer sandbox example. Set DIDIT_API_KEY and DIDIT_WEBHOOK_SECRET.
 * Keep the link file outside the web root, in a directory only you can read.
 *
 *     export DIDIT_BANK_LINK_FILE="$(mktemp -d)/link.json"
 *     php examples/bank_connection.php
 *     php -S 127.0.0.1:8080 examples/bank_connection.php
 *
 * Point the webhook destination at the receiver through your HTTPS tunnel,
 * then privately share the saved url with the customer. Use a new file per link.
 */

require __DIR__ . '/../vendor/autoload.php';

use Didit\DiditClient;
use Didit\Exception\DiditException;
use Didit\Exception\WebhookVerificationException;
use Didit\Webhooks\Events\BankConnectionCreatedEvent;

$file = getenv('DIDIT_BANK_LINK_FILE');
if (false === $file || '' === $file) {
    throw new RuntimeException('Set DIDIT_BANK_LINK_FILE to a private file path.');
}

$baseUrl = getenv('DIDIT_BASE_URL');
$didit = new DiditClient(baseUrl: false === $baseUrl || '' === $baseUrl ? null : $baseUrl);
if ('cli' === \PHP_SAPI) {
    umask(0077);
    $stream = fopen($file, 'xb');
    if (false === $stream) {
        throw new RuntimeException('Choose a new link file.');
    }

    try {
        $link = $didit->bank->createLink([
            'access' => 'ongoing',
            'data' => ['accounts'],
            'end_user' => ['reference' => 'customer-42'],
        ]);
        if (null === $link->id || null === $link->url) {
            throw new UnexpectedValueException('Missing link ID or hosted URL.');
        }
        $json = json_encode(['id' => $link->id, 'url' => $link->url], \JSON_THROW_ON_ERROR);
        if (\strlen($json) !== fwrite($stream, $json)) {
            throw new RuntimeException('Could not save the link.');
        }
    } finally {
        fclose($stream);
    }
    echo "Link saved. Share its URL privately with the customer.\n";

    return;
}

if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? null)) {
    http_response_code(405);

    return;
}

$body = file_get_contents('php://input', false, null, 0, 65537);
if (false === $body || \strlen($body) > 65536) {
    http_response_code(413);

    return;
}

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (\is_string($name) && str_starts_with($name, 'HTTP_') && \is_string($value)) {
        $headers[str_replace('_', '-', substr($name, 5))] = $value;
    }
}

try {
    $secret = getenv('DIDIT_WEBHOOK_SECRET');
    $event = $didit->webhooks->verify($body, $headers, false === $secret ? '' : $secret);
    if ($event instanceof BankConnectionCreatedEvent && null !== $event->connectionId) {
        $saved = file_get_contents($file);
        $link = json_decode(false === $saved ? '' : $saved, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($link) || !\is_string($link['id'] ?? null)) {
            throw new UnexpectedValueException('Missing saved link ID.');
        }

        $connectionId = $didit->bank->getLink($link['id'])->connectionId;
        if (null === $connectionId) {
            // The link may not yet expose its connection. Allow redelivery.
            http_response_code(503);

            return;
        }
        if ($connectionId === $event->connectionId) {
            // Both succeeded and partial first syncs have readable accounts.
            $count = 0;
            $cursor = null;
            do {
                $page = $didit->bank->listAccounts($connectionId, null === $cursor ? [] : ['cursor' => $cursor]);
                $count += \count($page->items ?? []);
                $cursor = $page->nextCursor;
            } while (null !== $cursor);

            error_log("Readable accounts: {$count}");
        }
    }
    http_response_code(204);
} catch (WebhookVerificationException) {
    http_response_code(401);
} catch (DiditException|\JsonException|UnexpectedValueException) {
    http_response_code(500);
}
