<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.connection.deleted`: the stored bank data was deleted.
 */
final class BankConnectionDeletedEvent extends BankEvent
{
    /** The connection id. */
    public readonly ?string $connectionId;

    /** `erased` when the data is gone. */
    public readonly ?string $erasureStatus;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $data = $this->data ?? [];
        $this->connectionId = Hydrate::string($data['connection_id'] ?? null);
        $this->erasureStatus = Hydrate::string($data['erasure_status'] ?? null);
    }
}
