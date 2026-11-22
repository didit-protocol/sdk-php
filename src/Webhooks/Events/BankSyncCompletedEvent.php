<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.sync.completed`: a sync finished; `outcome` says whether it fully succeeded.
 */
final class BankSyncCompletedEvent extends BankConnectionEvent
{
    /** The sync id. */
    public readonly ?string $syncId;

    /** `initial`, `scheduled` or `requested`. */
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
        $this->syncId = Hydrate::string($this->fields['sync_id'] ?? null);
        $this->trigger = Hydrate::string($this->fields['trigger'] ?? null);
        $this->outcome = Hydrate::string($this->fields['outcome'] ?? null);
        $this->changed = Hydrate::bool($this->fields['changed'] ?? null);
    }
}
