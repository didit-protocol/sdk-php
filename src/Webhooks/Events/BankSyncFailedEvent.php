<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.sync.failed`: syncs have been failing for 24 hours and are still retried.
 */
final class BankSyncFailedEvent extends BankConnectionEvent
{
    /** The latest failed sync. */
    public readonly ?string $syncId;

    /** Didit error code of the failure. */
    public readonly ?string $errorCode;

    /** When the syncs started to fail. */
    public readonly ?\DateTimeImmutable $failingSince;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $this->syncId = Hydrate::string($this->fields['sync_id'] ?? null);
        $this->errorCode = Hydrate::string($this->fields['error_code'] ?? null);
        $this->failingSince = Hydrate::dateTime($this->fields['failing_since'] ?? null);
    }
}
