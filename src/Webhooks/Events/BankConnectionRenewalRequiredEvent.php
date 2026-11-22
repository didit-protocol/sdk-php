<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * `bank.connection.renewal_required`: the consent ended or the bank wants the person to sign in again.
 */
final class BankConnectionRenewalRequiredEvent extends BankConnectionEvent
{
    /** The connection status. */
    public readonly ?string $status;

    /** `consent_expired` or `bank_requires_sign_in`. */
    public readonly ?string $reason;

    /** The consent's end, when there is one. */
    public readonly ?\DateTimeImmutable $renewBy;

    /** The last moment the same connection can be revived. */
    public readonly ?\DateTimeImmutable $renewableUntil;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $this->status = Hydrate::string($this->fields['status'] ?? null);
        $this->reason = Hydrate::string($this->fields['reason'] ?? null);
        $this->renewBy = Hydrate::dateTime($this->fields['renew_by'] ?? null);
        $this->renewableUntil = Hydrate::dateTime($this->fields['renewable_until'] ?? null);
    }
}
