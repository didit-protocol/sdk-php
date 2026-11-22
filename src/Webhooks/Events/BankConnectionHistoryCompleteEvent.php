<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.connection.history_complete`: the connection's history backfill ended.
 */
final class BankConnectionHistoryCompleteEvent extends BankConnectionEvent
{
    /** `complete`, or `partial` when the bank could not deliver every range. */
    public readonly ?string $status;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $this->status = Hydrate::string($this->fields['status'] ?? null);
    }
}
