<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.connection.consent_expiring`: the consent ends in 14, 7 or 1 day.
 */
final class BankConnectionConsentExpiringEvent extends BankEvent
{
    /** The connection id. */
    public readonly ?string $connectionId;

    /** Days until the consent ends: 14, 7 or 1. */
    public readonly ?int $daysLeft;

    /** When the consent ends. */
    public readonly ?\DateTimeImmutable $expiresAt;

    /**
     * A ready renew link when the application has automatic renew links on.
     * It is a credential: never log it.
     */
    public readonly ?string $renewUrl;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $data = $this->data ?? [];
        $this->connectionId = Hydrate::string($data['connection_id'] ?? null);
        $this->daysLeft = Hydrate::int($data['days_left'] ?? null);
        $this->expiresAt = Hydrate::dateTime($data['expires_at'] ?? null);
        $this->renewUrl = Hydrate::string($data['renew_url'] ?? null);
    }
}
