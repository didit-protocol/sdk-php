<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.connection.updated`: the connection status changed, for example a revocation started.
 *
 * A revocation carries `revocationStatus` and no `statusReason` or `endsAt`. Every other update carries
 * `statusReason` and `endsAt` (a date-time or null) and no `revocationStatus`.
 */
final class BankConnectionUpdatedEvent extends BankConnectionEvent
{
    /** The connection status. */
    public readonly ?string $status;

    /** `pending` while a revocation waits for the bank to confirm it. Only in the revocation payload. */
    public readonly ?string $revocationStatus;

    /** Why the connection is paused or failed (`insufficient_balance`, `first_sync_failed`); null otherwise. */
    public readonly ?string $statusReason;

    /** When the connection ends unless its coming month is paid first; null once it is paid or when nothing is pending. */
    public readonly ?\DateTimeImmutable $endsAt;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $this->status = Hydrate::string($this->fields['status'] ?? null);
        $this->revocationStatus = Hydrate::string($this->fields['revocation_status'] ?? null);
        $this->statusReason = Hydrate::string($this->fields['status_reason'] ?? null);
        $this->endsAt = Hydrate::dateTime($this->fields['ends_at'] ?? null);
    }
}
