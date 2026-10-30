<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;
use Didit\Webhooks\Event;

/**
 * A business event: {@see BusinessStatusUpdatedEvent} or
 * {@see BusinessDataUpdatedEvent}.
 */
abstract class BusinessEvent extends Event
{
    /** Your identifier of the business. */
    public readonly ?string $vendorBusinessId;

    /** Your identifier for the business, as given to Didit. */
    public readonly ?string $vendorData;

    /** Business status, for example `Approved`. */
    public readonly ?string $status;

    /**
     * @param array<string, mixed> $data the decoded JSON body
     */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->vendorBusinessId = Hydrate::string($data['vendor_business_id'] ?? null);
        $this->vendorData = Hydrate::string($data['vendor_data'] ?? null);
        $this->status = Hydrate::string($data['status'] ?? null);
    }
}
