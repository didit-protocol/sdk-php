<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;
use Didit\Webhooks\Event;

/**
 * A transaction monitoring event: {@see TransactionCreatedEvent} or
 * {@see TransactionStatusUpdatedEvent}.
 */
abstract class TransactionEvent extends Event
{
    /** Didit's identifier of the transaction (`transactions.retrieve`). */
    public readonly ?string $transactionId;

    /** Your identifier of the transaction, as given at creation. */
    public readonly ?string $txnId;

    /** Monitoring verdict, for example `Approved`, `In Review` or `Declined`. */
    public readonly ?string $status;

    /** Risk score the rules accumulated; higher is riskier. */
    public readonly int|float|null $score;

    /** Risk severity, for example `low` or `high`. */
    public readonly ?string $severity;

    /** Amount as a decimal string, for example `1500.00`. */
    public readonly ?string $amount;

    /** Currency code of the amount, for example `EUR` or `BTC`. */
    public readonly ?string $currency;

    /** `inbound` or `outbound`. */
    public readonly ?string $direction;

    /**
     * Whether the end user must act before the transaction can complete; an
     * object describing the action when Didit sends one.
     *
     * @var bool|array<string, mixed>|null
     */
    public readonly bool|array|null $actionRequired;

    /**
     * @param array<string, mixed> $data the decoded JSON body
     */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->transactionId = Hydrate::string($data['transaction_id'] ?? null);
        $this->txnId = Hydrate::string($data['txn_id'] ?? null);
        $this->status = Hydrate::string($data['status'] ?? null);
        $score = $data['score'] ?? null;
        $this->score = \is_int($score) || \is_float($score) ? $score : null;
        $this->severity = Hydrate::string($data['severity'] ?? null);
        $this->amount = Hydrate::string($data['amount'] ?? null);
        $this->currency = Hydrate::string($data['currency'] ?? null);
        $this->direction = Hydrate::string($data['direction'] ?? null);
        $actionRequired = $data['action_required'] ?? null;
        $this->actionRequired = Hydrate::bool($actionRequired) ?? Hydrate::assoc($actionRequired);
    }
}
