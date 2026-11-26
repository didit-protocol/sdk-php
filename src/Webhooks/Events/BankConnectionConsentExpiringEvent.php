<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.connection.consent_expiring`: the consent ends in 14, 7 or 1 day.
 */
final class BankConnectionConsentExpiringEvent extends BankConnectionEvent
{
    /** Days until the consent ends: 14, 7 or 1. */
    public readonly ?int $daysLeft;

    /** When the consent ends. */
    public readonly ?\DateTimeImmutable $consentExpiresAt;

    /** The consent's end: renew before it for no gap. */
    public readonly ?\DateTimeImmutable $renewBy;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $this->daysLeft = Hydrate::int($this->fields['days_left'] ?? null);
        $this->consentExpiresAt = Hydrate::dateTime($this->fields['consent_expires_at'] ?? null);
        $this->renewBy = Hydrate::dateTime($this->fields['renew_by'] ?? null);
    }
}
