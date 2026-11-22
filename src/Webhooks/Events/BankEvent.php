<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;
use Didit\Webhooks\Event;

/**
 * Base of the `bank.*` events. Their payload is in the event's `data` object.
 */
abstract class BankEvent extends Event
{
    /**
     * The event's `data` object, exactly as sent.
     *
     * @var array<string, mixed>|null
     */
    public readonly ?array $data;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $this->data = Hydrate::assoc($payload['data'] ?? null);
    }
}
