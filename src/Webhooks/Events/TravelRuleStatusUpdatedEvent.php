<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;
use Didit\Webhooks\Event;

/**
 * `travel_rule.status.updated`: the Travel Rule status of a transaction
 * changed.
 */
final class TravelRuleStatusUpdatedEvent extends Event
{
    /** Didit's identifier of the transaction. */
    public readonly ?string $transactionId;

    /** Your identifier of the transaction, as given at creation. */
    public readonly ?string $txnId;

    /** Travel Rule status, for example `COMPLETED`. */
    public readonly ?string $travelRuleStatus;

    /** The Travel Rule protocol used, for example `TRP`. */
    public readonly ?string $rail;

    /** `inbound` or `outbound`. */
    public readonly ?string $direction;

    /**
     * @param array<string, mixed> $data the decoded JSON body
     */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->transactionId = Hydrate::string($data['transaction_id'] ?? null);
        $this->txnId = Hydrate::string($data['txn_id'] ?? null);
        $this->travelRuleStatus = Hydrate::string($data['travel_rule_status'] ?? null);
        $this->rail = Hydrate::string($data['rail'] ?? null);
        $this->direction = Hydrate::string($data['direction'] ?? null);
    }
}
