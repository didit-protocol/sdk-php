<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * A bank connection event; every one names the connection it is about.
 */
abstract class BankConnectionEvent extends BankEvent
{
    /** The connection id. */
    public readonly ?string $connectionId;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $this->connectionId = Hydrate::string($this->fields['connection_id'] ?? null);
    }
}
