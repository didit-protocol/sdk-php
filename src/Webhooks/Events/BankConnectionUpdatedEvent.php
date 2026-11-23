<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.connection.updated`: the connection status changed, for example a revocation started.
 */
final class BankConnectionUpdatedEvent extends BankConnectionEvent
{
    /** The connection status. */
    public readonly ?string $status;

    /** `pending` while a revocation waits for the bank to confirm it. */
    public readonly ?string $revocationStatus;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $this->status = Hydrate::string($this->fields['status'] ?? null);
        $this->revocationStatus = Hydrate::string($this->fields['revocation_status'] ?? null);
    }
}
