<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;
use Didit\Webhooks\Event;

/**
 * Base of the `bank.*` events. The link events nest their fields in `data`;
 * the connection, data and sync events carry them next to the envelope.
 */
abstract class BankEvent extends Event
{
    /**
     * The event's own fields: the body itself, or its `data` object when the
     * payload nests them there.
     *
     * @var array<string, mixed>
     */
    protected readonly array $fields;

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
        $nested = Hydrate::assoc($payload['data'] ?? null) ?? [];
        $this->fields = $nested + $payload;
    }
}
