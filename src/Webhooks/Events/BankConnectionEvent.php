<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * A bank connection event that carries the connection id and its status.
 */
abstract class BankConnectionEvent extends BankEvent
{
    /** The connection id. */
    public readonly ?string $connectionId;

    /** The connection status, for example `active` or `renewal_required`. */
    public readonly ?string $status;

    /** Why the connection is in this status, when the API gives a reason. */
    public readonly ?string $statusReason;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $data = $this->data ?? [];
        $this->connectionId = Hydrate::string($data['connection_id'] ?? null);
        $this->status = Hydrate::string($data['status'] ?? null);
        $this->statusReason = Hydrate::string($data['status_reason'] ?? null);
    }
}
