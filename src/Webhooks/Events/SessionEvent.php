<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;
use Didit\Types\SessionDecision;
use Didit\Webhooks\Event;

/**
 * A verification session event: {@see StatusUpdatedEvent} or
 * {@see DataUpdatedEvent}. Business (KYB) sessions use the same events with
 * `session_kind` `business`.
 */
abstract class SessionEvent extends Event
{
    /** The session. */
    public readonly ?string $sessionId;

    /** The business session, for business (KYB) sessions; equal to `session_id`. */
    public readonly ?string $businessSessionId;

    /** `business` for business (KYB) sessions; absent (null) for user sessions. */
    public readonly ?string $sessionKind;

    /** Session status, for example `Approved`, `Declined`, `In Review` or `Resubmitted`. */
    public readonly ?string $status;

    /** The workflow the session runs. */
    public readonly ?string $workflowId;

    /** Version of the workflow the session is pinned to. */
    public readonly ?int $workflowVersion;

    /** Your identifier for the user or business, as given at session creation. */
    public readonly ?string $vendorData;

    /** @var array<string, mixed>|null the metadata given at session creation */
    public readonly ?array $metadata;

    /**
     * The decision, sent once the session is `Approved`, `Declined`,
     * `In Review` or `Abandoned`.
     */
    public readonly ?SessionDecision $decision;

    /**
     * @var array<string, mixed>|null for `Resubmitted` sessions:
     *                                `nodes_to_resubmit` and `reasons`
     */
    public readonly ?array $resubmitInfo;

    /**
     * @param array<string, mixed> $data the decoded JSON body
     */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->sessionId = Hydrate::string($data['session_id'] ?? null);
        $this->businessSessionId = Hydrate::string($data['business_session_id'] ?? null);
        $this->sessionKind = Hydrate::string($data['session_kind'] ?? null);
        $this->status = Hydrate::string($data['status'] ?? null);
        $this->workflowId = Hydrate::string($data['workflow_id'] ?? null);
        $this->workflowVersion = Hydrate::int($data['workflow_version'] ?? null);
        $this->vendorData = Hydrate::string($data['vendor_data'] ?? null);
        $this->metadata = Hydrate::assoc($data['metadata'] ?? null);
        $this->decision = Hydrate::model($data['decision'] ?? null, SessionDecision::class);
        $this->resubmitInfo = Hydrate::assoc($data['resubmit_info'] ?? null);
    }
}
