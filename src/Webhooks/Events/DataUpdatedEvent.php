<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

/**
 * `data.updated`: data of a verification session changed (for example after
 * a manual review edited the extracted data).
 */
final class DataUpdatedEvent extends SessionEvent
{
}
