<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.connection.renewed`: a renew link completed; the connection id is unchanged.
 */
final class BankConnectionRenewedEvent extends BankConnectionEvent
{
    /** The connection status. */
    public readonly ?string $status;

    /** The consent version the renewal created. */
    public readonly ?int $consentVersion;

    /** The renew link that completed, when there is one. */
    public readonly ?string $linkId;

    /** When the renewed consent ends. */
    public readonly ?\DateTimeImmutable $consentExpiresAt;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $this->status = Hydrate::string($this->fields['status'] ?? null);
        $this->consentVersion = Hydrate::int($this->fields['consent_version'] ?? null);
        $this->linkId = Hydrate::string($this->fields['link_id'] ?? null);
        $this->consentExpiresAt = Hydrate::dateTime($this->fields['consent_expires_at'] ?? null);
    }
}
