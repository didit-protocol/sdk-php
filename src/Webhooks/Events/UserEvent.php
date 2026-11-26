<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;
use Didit\Webhooks\Event;

/**
 * A user event: {@see UserStatusUpdatedEvent} or {@see UserDataUpdatedEvent}.
 */
abstract class UserEvent extends Event
{
    /** Your identifier of the user (`vendor_data` of their sessions). */
    public readonly ?string $vendorUserId;

    /** Your identifier for the user, as given to Didit. */
    public readonly ?string $vendorData;

    /** User status, for example `Approved`. */
    public readonly ?string $status;

    /**
     * @param array<string, mixed> $data the decoded JSON body
     */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->vendorUserId = Hydrate::string($data['vendor_user_id'] ?? null);
        $this->vendorData = Hydrate::string($data['vendor_data'] ?? null);
        $this->status = Hydrate::string($data['status'] ?? null);
    }
}
