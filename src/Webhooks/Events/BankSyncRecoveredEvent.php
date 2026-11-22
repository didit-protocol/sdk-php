<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.sync.recovered`: syncs work again after failures.
 */
final class BankSyncRecoveredEvent extends BankConnectionEvent
{
    /** The sync that succeeded. */
    public readonly ?string $syncId;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $this->syncId = Hydrate::string($this->fields['sync_id'] ?? null);
    }
}
