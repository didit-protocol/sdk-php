<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;

/**
 * A bank link event: the link completed, was cancelled, expired or failed.
 */
abstract class BankLinkEvent extends BankEvent
{
    /** The link id. */
    public readonly ?string $linkId;

    /** `completed`, `cancelled`, `expired` or `failed`. */
    public readonly ?string $status;

    /** The connection the link created or renewed, when there is one. */
    public readonly ?string $connectionId;

    /**
     * The connection a renewal link targets, set when the link was created; null on a link that renews
     * nothing. Absent on an event stored before the field existed: an older failed, cancelled or expired
     * renewal event carries that connection in `connectionId`.
     */
    public readonly ?string $renewsConnectionId;

    /** The ownership result, when one was asked for. */
    public readonly ?string $checkId;

    /**
     * Why a link failed, for example `bank_unavailable` or `consent_declined`.
     */
    public readonly ?string $errorCode;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $data = Hydrate::assoc($payload['data'] ?? null) ?? [];
        $this->linkId = Hydrate::string($data['link_id'] ?? null);
        $this->status = Hydrate::string($data['status'] ?? null);
        $this->connectionId = Hydrate::string($data['connection_id'] ?? null);
        $this->renewsConnectionId = Hydrate::string($data['renews_connection_id'] ?? null);
        $this->checkId = Hydrate::string($data['check_id'] ?? null);
        $this->errorCode = Hydrate::string($data['error_code'] ?? null);
    }
}
