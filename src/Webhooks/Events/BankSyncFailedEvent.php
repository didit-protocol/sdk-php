<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.sync.failed`: a sync failed and the connection's sync health is failing.
 */
final class BankSyncFailedEvent extends BankEvent
{
    /** The connection id. */
    public readonly ?string $connectionId;

    /** The latest failed sync, when there is one. */
    public readonly ?string $syncId;

    /** The error code, when the API gives one. */
    public readonly ?string $errorCode;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $data = $this->data ?? [];
        $this->connectionId = Hydrate::string($data['connection_id'] ?? null);
        $this->syncId = Hydrate::string($data['sync_id'] ?? null);
        $this->errorCode = Hydrate::string($data['error_code'] ?? null);
    }
}
