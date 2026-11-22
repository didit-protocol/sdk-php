<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.connection.deleted`: the stored bank data was deleted.
 */
final class BankConnectionDeletedEvent extends BankConnectionEvent
{
    /** The connection status. */
    public readonly ?string $status;

    /** `erased` when the data is gone. */
    public readonly ?string $erasureStatus;

    /** Why the connection was deleted. */
    public readonly ?string $reason;

    /** When the deletion finished. */
    public readonly ?\DateTimeImmutable $erasedAt;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $this->status = Hydrate::string($this->fields['status'] ?? null);
        $this->erasureStatus = Hydrate::string($this->fields['erasure_status'] ?? null);
        $this->reason = Hydrate::string($this->fields['reason'] ?? null);
        $this->erasedAt = Hydrate::dateTime($this->fields['erased_at'] ?? null);
    }
}
