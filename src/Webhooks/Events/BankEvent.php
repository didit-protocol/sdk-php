<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Webhooks\Event;

/**
 * Base of the `bank.*` events. Only the four `bank.link.*` events nest their
 * fields in `data`; the connection, data and sync events carry them next to
 * the envelope, as their schemas in the OpenAPI contract do.
 */
abstract class BankEvent extends Event
{
    /**
     * The event's own fields: the body itself. {@see BankLinkEvent} reads the
     * link's fields from `data` instead.
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
        $this->fields = $payload;
    }
}
