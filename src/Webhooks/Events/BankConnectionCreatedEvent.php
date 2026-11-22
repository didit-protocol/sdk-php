<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

/**
 * `bank.connection.created`: the first sync finished and the accounts are readable.
 */
final class BankConnectionCreatedEvent extends BankConnectionEvent
{
}
