<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.connection.revoked`: access ended; `endedBy` says who ended it.
 */
final class BankConnectionRevokedEvent extends BankEvent
{
    /** The connection id. */
    public readonly ?string $connectionId;

    /** `revocation_pending`, `revoked` or `revocation_failed`. */
    public readonly ?string $status;

    /** `end_user`, `bank`, `customer` or `unknown`. */
    public readonly ?string $endedBy;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $data = $this->data ?? [];
        $this->connectionId = Hydrate::string($data['connection_id'] ?? null);
        $this->status = Hydrate::string($data['status'] ?? null);
        $this->endedBy = Hydrate::string($data['ended_by'] ?? null);
    }
}
