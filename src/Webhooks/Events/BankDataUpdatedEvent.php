<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.data.updated`: a sync stored changes; read them with the change feed.
 */
final class BankDataUpdatedEvent extends BankConnectionEvent
{
    /** The sync that stored the changes. */
    public readonly ?string $syncId;

    /** `initial`, `scheduled` or `requested`. */
    public readonly ?string $trigger;

    /**
     * The accounts whose transactions changed, with their counts.
     *
     * @var list<BankDataUpdatedAccount>|null
     */
    public readonly ?array $accounts;

    /** Whether any balance changed. */
    public readonly ?bool $balancesChanged;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $this->syncId = Hydrate::string($this->fields['sync_id'] ?? null);
        $this->trigger = Hydrate::string($this->fields['trigger'] ?? null);
        $this->accounts = Hydrate::list(
            $this->fields['accounts'] ?? null,
            static fn (mixed $value) => Hydrate::model($value, BankDataUpdatedAccount::class),
        );
        $this->balancesChanged = Hydrate::bool($this->fields['balances_changed'] ?? null);
    }
}
