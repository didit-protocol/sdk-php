<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Webhooks\Event;

/**
 * An event whose `webhook_type` this SDK version does not know. The whole
 * payload stays readable through `toArray()`, `get()` and array access.
 */
final class UnknownEvent extends Event
{
}
