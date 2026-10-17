<?php

declare(strict_types=1);

namespace Didit\Webhooks;

use Didit\Exception\ConfigurationException;
use Didit\Exception\WebhookVerificationException;
use Psr\Http\Message\MessageInterface;

/**
 * Adds `verify()` and `verifyParsed()` to the `webhooks` resource, so
 * `$didit->webhooks->verify($rawBody, $headers, $secret)` works next to
 * `$didit->webhooks->destinations`. Both delegate to {@see Webhook}, which is
 * also usable without a client.
 */
trait VerifiesWebhooks
{
    /**
     * Verifies a delivery from its raw body and returns the typed event; see
     * {@see Webhook::verify()}.
     *
     * @param array<string, string|list<string>>|MessageInterface $headers
     * @param string|array<string>                               $secret
     *
     * @throws WebhookVerificationException
     * @throws ConfigurationException
     */
    public function verify(
        string $rawBody,
        array|MessageInterface $headers,
        string|array $secret,
        int $tolerance = Webhook::DEFAULT_TOLERANCE,
        bool $allowSimple = false,
        ?int $now = null,
    ): Event {
        return Webhook::verify($rawBody, $headers, $secret, $tolerance, $allowSimple, $now);
    }

    /**
     * Verifies a delivery from an already decoded body (`X-Signature-V2`
     * only) and returns the typed event; see {@see Webhook::verifyParsed()}.
     *
     * @param array<mixed>|object                                $payload
     * @param array<string, string|list<string>>|MessageInterface $headers
     * @param string|array<string>                               $secret
     *
     * @throws WebhookVerificationException
     * @throws ConfigurationException
     */
    public function verifyParsed(
        array|object $payload,
        array|MessageInterface $headers,
        string|array $secret,
        int $tolerance = Webhook::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): Event {
        return Webhook::verifyParsed($payload, $headers, $secret, $tolerance, $now);
    }
}
