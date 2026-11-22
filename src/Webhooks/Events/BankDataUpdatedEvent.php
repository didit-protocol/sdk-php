<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.data.updated`: a sync stored changes; read them with the change feed.
 */
final class BankDataUpdatedEvent extends BankEvent
{
    /** The connection id. */
    public readonly ?string $connectionId;

    /** The sync that stored the changes. */
    public readonly ?string $syncId;

    /**
     * Accounts whose data changed.
     *
     * @var list<string>|null
     */
    public readonly ?array $accountIds;

    /** Transactions added. */
    public readonly ?int $added;

    /** Transactions modified. */
    public readonly ?int $modified;

    /** Transactions removed. */
    public readonly ?int $removed;

    /** Whether balances changed. */
    public readonly ?bool $balancesChanged;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $data = $this->data ?? [];
        $this->connectionId = Hydrate::string($data['connection_id'] ?? null);
        $this->syncId = Hydrate::string($data['sync_id'] ?? null);
        $this->accountIds = Hydrate::list($data['account_ids'] ?? null, Hydrate::string(...));
        $this->added = Hydrate::int($data['added'] ?? null);
        $this->modified = Hydrate::int($data['modified'] ?? null);
        $this->removed = Hydrate::int($data['removed'] ?? null);
        $this->balancesChanged = Hydrate::bool($data['balances_changed'] ?? null);
    }
}
