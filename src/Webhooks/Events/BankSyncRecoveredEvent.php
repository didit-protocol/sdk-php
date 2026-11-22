<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.sync.recovered`: a sync succeeded after failures.
 */
final class BankSyncRecoveredEvent extends BankEvent
{
    /** The connection id. */
    public readonly ?string $connectionId;

    /** The sync that succeeded. */
    public readonly ?string $syncId;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $data = $this->data ?? [];
        $this->connectionId = Hydrate::string($data['connection_id'] ?? null);
        $this->syncId = Hydrate::string($data['sync_id'] ?? null);
    }
}
