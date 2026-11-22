<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.sync.completed`: a sync finished; `outcome` says whether it fully succeeded.
 */
final class BankSyncCompletedEvent extends BankEvent
{
    /** The connection id. */
    public readonly ?string $connectionId;

    /** The sync id. */
    public readonly ?string $syncId;

    /** `scheduled`, `requested` or `initial`. */
    public readonly ?string $trigger;

    /** `succeeded`, `partial` or `failed`. */
    public readonly ?string $outcome;

    /** Whether the sync stored changes. */
    public readonly ?bool $changed;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $data = $this->data ?? [];
        $this->connectionId = Hydrate::string($data['connection_id'] ?? null);
        $this->syncId = Hydrate::string($data['sync_id'] ?? null);
        $this->trigger = Hydrate::string($data['trigger'] ?? null);
        $this->outcome = Hydrate::string($data['outcome'] ?? null);
        $this->changed = Hydrate::bool($data['changed'] ?? null);
    }
}
