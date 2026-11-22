<?php

declare(strict_types=1);

namespace Didit\Webhooks;

/**
 * The `webhook_type` values this SDK version knows. Didit may add types at any
 * time; {@see Event::fromPayload()} returns them as
 * {@see Events\UnknownEvent}.
 */
final class EventType
{
    /** A verification session changed status. */
    public const STATUS_UPDATED = 'status.updated';

    /** Data of a verification session changed. */
    public const DATA_UPDATED = 'data.updated';

    /** A user changed status. */
    public const USER_STATUS_UPDATED = 'user.status.updated';

    /** Data of a user changed. */
    public const USER_DATA_UPDATED = 'user.data.updated';

    /** A business changed status. */
    public const BUSINESS_STATUS_UPDATED = 'business.status.updated';

    /** Data of a business changed. */
    public const BUSINESS_DATA_UPDATED = 'business.data.updated';

    /** An activity was recorded. */
    public const ACTIVITY_CREATED = 'activity.created';

    /** A transaction was created. */
    public const TRANSACTION_CREATED = 'transaction.created';

    /** A transaction changed status. */
    public const TRANSACTION_STATUS_UPDATED = 'transaction.status.updated';

    /** The Travel Rule status of a transaction changed. */
    public const TRAVEL_RULE_STATUS_UPDATED = 'travel_rule.status.updated';

    /** The compliance state of a workflow changed. */
    public const WORKFLOW_COMPLIANCE_CHANGED = 'workflow.compliance_changed';

    /** An asynchronous KYB registry search finished. */
    public const KYB_REGISTRY_SEARCH_RESOLVED = 'kyb.registry_search.resolved';

    private function __construct()
    {
    }
}
