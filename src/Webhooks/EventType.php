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

    /** A bank link completed and yielded a connection. */
    public const BANK_LINK_COMPLETED = 'bank.link.completed';

    /** A bank link was cancelled. */
    public const BANK_LINK_CANCELLED = 'bank.link.cancelled';

    /** A bank link expired. */
    public const BANK_LINK_EXPIRED = 'bank.link.expired';

    /** A bank link failed. */
    public const BANK_LINK_FAILED = 'bank.link.failed';

    /** The first sync of a bank connection finished. */
    public const BANK_CONNECTION_CREATED = 'bank.connection.created';

    /** The status, consent or sync state of a bank connection changed. */
    public const BANK_CONNECTION_UPDATED = 'bank.connection.updated';

    /** The consent of a bank connection is about to end. */
    public const BANK_CONNECTION_CONSENT_EXPIRING = 'bank.connection.consent_expiring';

    /** A bank connection needs the person to sign in again. */
    public const BANK_CONNECTION_RENEWAL_REQUIRED = 'bank.connection.renewal_required';

    /** A bank connection was renewed. */
    public const BANK_CONNECTION_RENEWED = 'bank.connection.renewed';

    /** Access to a bank connection was revoked. */
    public const BANK_CONNECTION_REVOKED = 'bank.connection.revoked';

    /** The stored data of a bank connection was deleted. */
    public const BANK_CONNECTION_DELETED = 'bank.connection.deleted';

    /** The history backfill of a bank connection finished. */
    public const BANK_CONNECTION_HISTORY_COMPLETE = 'bank.connection.history_complete';

    /** A bank sync stored changes. */
    public const BANK_DATA_UPDATED = 'bank.data.updated';

    /** A bank sync completed. */
    public const BANK_SYNC_COMPLETED = 'bank.sync.completed';

    /** A bank sync failed. */
    public const BANK_SYNC_FAILED = 'bank.sync.failed';

    /** A bank sync recovered after failures. */
    public const BANK_SYNC_RECOVERED = 'bank.sync.recovered';

    private function __construct()
    {
    }
}
