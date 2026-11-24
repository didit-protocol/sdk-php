<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.connection.created`: the first sync finished and the accounts are readable.
 */
final class BankConnectionCreatedEvent extends BankConnectionEvent
{
    /** `succeeded` or `partial`: how the first sync ended. */
    public readonly ?string $initialStatus;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $this->initialStatus = Hydrate::string($this->fields['initial_status'] ?? null);
    }
}
