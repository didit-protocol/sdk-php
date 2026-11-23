<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * Transaction changes in one account, in a `bank.data.updated` event.
 */
final class BankDataUpdatedAccount extends BaseModel
{
    /** The account id. */
    public readonly ?string $accountId;

    /** Transactions added. */
    public readonly ?int $added;

    /** Transactions modified. */
    public readonly ?int $modified;

    /** Transactions removed. */
    public readonly ?int $removed;

    /**
     * @param array<string, mixed> $data the decoded JSON object
     */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->accountId = Hydrate::string($data['account_id'] ?? null);
        $this->added = Hydrate::int($data['added'] ?? null);
        $this->modified = Hydrate::int($data['modified'] ?? null);
        $this->removed = Hydrate::int($data['removed'] ?? null);
    }
}
