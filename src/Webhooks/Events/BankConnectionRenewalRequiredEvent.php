<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

/**
 * `bank.connection.renewal_required`: the consent ended or the bank wants the person to sign in again.
 */
final class BankConnectionRenewalRequiredEvent extends BankConnectionEvent
{
}
