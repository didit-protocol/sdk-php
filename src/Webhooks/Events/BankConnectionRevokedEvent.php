<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.connection.revoked`: access ended and the bank confirmed it.
 */
final class BankConnectionRevokedEvent extends BankConnectionEvent
{
    /** The consent status after revocation. */
    public readonly ?string $status;

    /** `end_user`, `bank`, `customer`, `system` or `unknown`. */
    public readonly ?string $endedBy;

    /** Why the connection is in this status. */
    public readonly ?string $statusReason;

    /** When access ended. */
    public readonly ?\DateTimeImmutable $endedAt;

    /** How long the stored data stays readable. */
    public readonly ?\DateTimeImmutable $retainUntil;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $this->status = Hydrate::string($this->fields['status'] ?? null);
        $this->endedBy = Hydrate::string($this->fields['ended_by'] ?? null);
        $this->statusReason = Hydrate::string($this->fields['status_reason'] ?? null);
        $this->endedAt = Hydrate::dateTime($this->fields['ended_at'] ?? null);
        $this->retainUntil = Hydrate::dateTime($this->fields['retain_until'] ?? null);
    }
}
