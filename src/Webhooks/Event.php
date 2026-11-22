<?php

declare(strict_types=1);

namespace Didit\Webhooks;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;
use Didit\Webhooks\Events\ActivityCreatedEvent;
use Didit\Webhooks\Events\BusinessDataUpdatedEvent;
use Didit\Webhooks\Events\BusinessStatusUpdatedEvent;
use Didit\Webhooks\Events\DataUpdatedEvent;
use Didit\Webhooks\Events\KybRegistrySearchResolvedEvent;
use Didit\Webhooks\Events\StatusUpdatedEvent;
use Didit\Webhooks\Events\TransactionCreatedEvent;
use Didit\Webhooks\Events\TransactionStatusUpdatedEvent;
use Didit\Webhooks\Events\TravelRuleStatusUpdatedEvent;
use Didit\Webhooks\Events\UnknownEvent;
use Didit\Webhooks\Events\UserDataUpdatedEvent;
use Didit\Webhooks\Events\UserStatusUpdatedEvent;
use Didit\Webhooks\Events\WorkflowComplianceChangedEvent;

/**
 * A webhook event. {@see Webhook::verify()} returns the subclass that matches
 * `webhook_type` ({@see EventType}), or {@see UnknownEvent} for a type this
 * SDK version does not know.
 *
 * Every field Didit sent stays readable through {@see self::toArray()} and
 * array access, including fields this SDK version does not type yet.
 */
abstract class Event extends BaseModel
{
    public const ENVIRONMENT_LIVE = 'live';

    public const ENVIRONMENT_SANDBOX = 'sandbox';

    /**
     * Unique id of the event. Didit retries failed deliveries with the same
     * id: use it to ignore duplicates.
     */
    public readonly ?string $eventId;

    /** The event type ({@see EventType}). */
    public readonly ?string $webhookType;

    /** Unix seconds when the delivery was signed (`X-Timestamp` repeats it). */
    public readonly ?int $timestamp;

    /** Unix seconds when the change the event reports happened. */
    public readonly ?int $createdAt;

    /** The application the event belongs to. */
    public readonly ?string $applicationId;

    /** `live` or `sandbox` ({@see self::ENVIRONMENT_LIVE}, {@see self::ENVIRONMENT_SANDBOX}). */
    public readonly ?string $environment;

    private ?string $verifiedWith = null;

    /**
     * @param array<string, mixed> $data the decoded JSON body
     */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->eventId = Hydrate::string($data['event_id'] ?? null);
        $this->webhookType = self::typeOf($data);
        $this->timestamp = Hydrate::int($data['timestamp'] ?? null);
        $this->createdAt = Hydrate::int($data['created_at'] ?? null);
        $this->applicationId = Hydrate::string($data['application_id'] ?? null);
        $this->environment = Hydrate::string($data['environment'] ?? null);
    }

    /**
     * Builds the event class that matches the payload's `webhook_type`,
     * without verifying anything. Use {@see Webhook::verify()} on deliveries.
     *
     * @param array<string, mixed> $payload the decoded JSON body
     */
    public static function fromPayload(array $payload): self
    {
        return match (self::typeOf($payload)) {
            EventType::STATUS_UPDATED => new StatusUpdatedEvent($payload),
            EventType::DATA_UPDATED => new DataUpdatedEvent($payload),
            EventType::USER_STATUS_UPDATED => new UserStatusUpdatedEvent($payload),
            EventType::USER_DATA_UPDATED => new UserDataUpdatedEvent($payload),
            EventType::BUSINESS_STATUS_UPDATED => new BusinessStatusUpdatedEvent($payload),
            EventType::BUSINESS_DATA_UPDATED => new BusinessDataUpdatedEvent($payload),
            EventType::ACTIVITY_CREATED => new ActivityCreatedEvent($payload),
            EventType::TRANSACTION_CREATED => new TransactionCreatedEvent($payload),
            EventType::TRANSACTION_STATUS_UPDATED => new TransactionStatusUpdatedEvent($payload),
            EventType::TRAVEL_RULE_STATUS_UPDATED => new TravelRuleStatusUpdatedEvent($payload),
            EventType::WORKFLOW_COMPLIANCE_CHANGED => new WorkflowComplianceChangedEvent($payload),
            EventType::KYB_REGISTRY_SEARCH_RESOLVED => new KybRegistrySearchResolvedEvent($payload),
            default => new UnknownEvent($payload),
        };
    }

    /**
     * @internal used by {@see Webhook}
     *
     * @param array<string, mixed> $payload
     */
    public static function fromVerifiedPayload(array $payload, string $verifiedWith): self
    {
        $event = self::fromPayload($payload);
        $event->verifiedWith = $verifiedWith;

        return $event;
    }

    /**
     * The signature header that verified this event, for example
     * `X-Signature-V2` or `X-Signature-Simple-Previous`; null when the event
     * was built with {@see self::fromPayload()} and never verified.
     */
    public function verifiedWith(): ?string
    {
        return $this->verifiedWith;
    }

    /**
     * Whether a signature covered the whole body. False when only
     * `X-Signature-Simple` matched (it signs `timestamp`, `session_id`,
     * `status` and `webhook_type`, nothing else): re-fetch the record from
     * the API before trusting any other field. Also false for events built
     * with {@see self::fromPayload()}.
     */
    public function bodyAuthenticated(): bool
    {
        return \in_array($this->verifiedWith, [
            Webhook::HEADER_SIGNATURE,
            Webhook::HEADER_SIGNATURE_V2,
            Webhook::HEADER_SIGNATURE_PREVIOUS,
            Webhook::HEADER_SIGNATURE_V2_PREVIOUS,
        ], true);
    }

    /**
     * `webhook_type`, or `event_type` for callbacks that name their type that
     * way (the KYB registry search callback).
     *
     * @param array<string, mixed> $payload
     */
    private static function typeOf(array $payload): ?string
    {
        return Hydrate::string($payload['webhook_type'] ?? null) ?? Hydrate::string($payload['event_type'] ?? null);
    }
}
