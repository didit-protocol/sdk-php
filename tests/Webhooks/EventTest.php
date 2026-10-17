<?php

declare(strict_types=1);

namespace Didit\Tests\Webhooks;

use Didit\Types\SessionsRetrieveDecisionResponse;
use Didit\Webhooks\Event;
use Didit\Webhooks\Events\ActivityCreatedEvent;
use Didit\Webhooks\Events\BusinessDataUpdatedEvent;
use Didit\Webhooks\Events\BusinessEvent;
use Didit\Webhooks\Events\BusinessStatusUpdatedEvent;
use Didit\Webhooks\Events\DataUpdatedEvent;
use Didit\Webhooks\Events\KybRegistrySearchResolvedEvent;
use Didit\Webhooks\Events\SessionEvent;
use Didit\Webhooks\Events\StatusUpdatedEvent;
use Didit\Webhooks\Events\TransactionCreatedEvent;
use Didit\Webhooks\Events\TransactionEvent;
use Didit\Webhooks\Events\TransactionStatusUpdatedEvent;
use Didit\Webhooks\Events\TravelRuleStatusUpdatedEvent;
use Didit\Webhooks\Events\UnknownEvent;
use Didit\Webhooks\Events\UserDataUpdatedEvent;
use Didit\Webhooks\Events\UserEvent;
use Didit\Webhooks\Events\UserStatusUpdatedEvent;
use Didit\Webhooks\Events\WorkflowComplianceChangedEvent;
use Didit\Webhooks\EventType;
use Didit\Webhooks\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    private const ENVELOPE = [
        'event_id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
        'application_id' => '0b5e8d2c-1f4a-4c3b-9d6e-2a7f8c1b3e5d',
        'environment' => 'sandbox',
        'timestamp' => 1760702400,
        'created_at' => 1760702399,
    ];

    /**
     * @return array<string, array{string, class-string<Event>}>
     */
    public static function types(): array
    {
        return [
            'status.updated' => [EventType::STATUS_UPDATED, StatusUpdatedEvent::class],
            'data.updated' => [EventType::DATA_UPDATED, DataUpdatedEvent::class],
            'user.status.updated' => [EventType::USER_STATUS_UPDATED, UserStatusUpdatedEvent::class],
            'user.data.updated' => [EventType::USER_DATA_UPDATED, UserDataUpdatedEvent::class],
            'business.status.updated' => [EventType::BUSINESS_STATUS_UPDATED, BusinessStatusUpdatedEvent::class],
            'business.data.updated' => [EventType::BUSINESS_DATA_UPDATED, BusinessDataUpdatedEvent::class],
            'activity.created' => [EventType::ACTIVITY_CREATED, ActivityCreatedEvent::class],
            'transaction.created' => [EventType::TRANSACTION_CREATED, TransactionCreatedEvent::class],
            'transaction.status.updated' => [EventType::TRANSACTION_STATUS_UPDATED, TransactionStatusUpdatedEvent::class],
            'travel_rule.status.updated' => [EventType::TRAVEL_RULE_STATUS_UPDATED, TravelRuleStatusUpdatedEvent::class],
            'workflow.compliance_changed' => [EventType::WORKFLOW_COMPLIANCE_CHANGED, WorkflowComplianceChangedEvent::class],
            'kyb.registry_search.resolved' => [EventType::KYB_REGISTRY_SEARCH_RESOLVED, KybRegistrySearchResolvedEvent::class],
            'newer backend type' => ['transaction_rule_suggestions.created', UnknownEvent::class],
            'authentication type' => ['auth.user.created', UnknownEvent::class],
            'empty type' => ['', UnknownEvent::class],
        ];
    }

    /**
     * @param class-string<Event> $class
     */
    #[DataProvider('types')]
    public function testDispatchesOnWebhookType(string $type, string $class): void
    {
        $payload = self::ENVELOPE + ['webhook_type' => $type, 'brand_new_field' => ['nested' => true]];
        $event = Event::fromPayload($payload);

        self::assertInstanceOf($class, $event);
        self::assertSame($type, $event->webhookType);
        self::assertSame('7c9e6679-7425-40de-944b-e07fc1f90ae7', $event->eventId);
        self::assertSame('0b5e8d2c-1f4a-4c3b-9d6e-2a7f8c1b3e5d', $event->applicationId);
        self::assertSame(Event::ENVIRONMENT_SANDBOX, $event->environment);
        self::assertSame(1760702400, $event->timestamp);
        self::assertSame(1760702399, $event->createdAt);
        // Fields this SDK version does not know stay readable.
        self::assertSame(['nested' => true], $event['brand_new_field']);
        self::assertSame(['nested' => true], $event->get('brand_new_field'));
        self::assertTrue($event->has('brand_new_field'));
        self::assertSame($payload, $event->toArray());
    }

    /**
     * @param class-string<Event> $class
     */
    #[DataProvider('types')]
    public function testVerifyReturnsTheTypedEvent(string $type, string $class): void
    {
        $body = '{"event_id":"e","timestamp":1760702400,"webhook_type":"' . $type . '"}';
        $event = Webhook::verify($body, ['X-Signature' => hash_hmac('sha256', $body, 'whsec_x')], 'whsec_x', now: 1760702400);

        self::assertInstanceOf($class, $event);
        self::assertSame('X-Signature', $event->verifiedWith());
    }

    public function testMissingOrInvalidTypeIsUnknown(): void
    {
        self::assertInstanceOf(UnknownEvent::class, Event::fromPayload([]));
        $event = Event::fromPayload(['webhook_type' => ['status.updated'], 'timestamp' => 'soon']);
        self::assertInstanceOf(UnknownEvent::class, $event);
        self::assertNull($event->webhookType);
        self::assertNull($event->timestamp);
        self::assertSame(['status.updated'], $event['webhook_type']);
    }

    public function testStatusUpdatedSessionEvent(): void
    {
        $event = Event::fromPayload(self::ENVELOPE + [
            'webhook_type' => 'status.updated',
            'session_id' => '11111111-2222-3333-4444-555555555555',
            'status' => 'Approved',
            'workflow_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'workflow_version' => 3,
            'vendor_data' => 'user-42',
            'metadata' => ['plan' => 'pro'],
            'decision' => ['session_id' => '11111111-2222-3333-4444-555555555555', 'status' => 'Approved', 'id_verifications' => [['status' => 'Approved']], 'future_block' => 1],
            'trigger' => 'manual_review',
        ]);

        self::assertInstanceOf(StatusUpdatedEvent::class, $event);
        self::assertInstanceOf(SessionEvent::class, $event);
        self::assertSame('11111111-2222-3333-4444-555555555555', $event->sessionId);
        self::assertNull($event->businessSessionId);
        self::assertNull($event->sessionKind);
        self::assertSame('Approved', $event->status);
        self::assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $event->workflowId);
        self::assertSame(3, $event->workflowVersion);
        self::assertSame('user-42', $event->vendorData);
        self::assertSame(['plan' => 'pro'], $event->metadata);
        self::assertNull($event->resubmitInfo);
        self::assertInstanceOf(SessionsRetrieveDecisionResponse::class, $event->decision);
        self::assertSame('Approved', $event->decision->status);
        self::assertSame('11111111-2222-3333-4444-555555555555', $event->decision->sessionId);
        self::assertSame(1, $event->decision->get('future_block'));
        self::assertSame('manual_review', $event['trigger']);
    }

    public function testResubmittedBusinessSessionEvent(): void
    {
        $event = Event::fromPayload(self::ENVELOPE + [
            'webhook_type' => 'data.updated',
            'session_id' => '22222222-2222-3333-4444-555555555555',
            'business_session_id' => '22222222-2222-3333-4444-555555555555',
            'session_kind' => 'business',
            'status' => 'Resubmitted',
            'vendor_business_id' => 'acme-1',
            'resubmit_info' => ['nodes_to_resubmit' => ['kyb_documents'], 'reasons' => ['kyb_documents' => 'Blurry']],
            'decision' => null,
        ]);

        self::assertInstanceOf(DataUpdatedEvent::class, $event);
        self::assertSame('22222222-2222-3333-4444-555555555555', $event->businessSessionId);
        self::assertSame('business', $event->sessionKind);
        self::assertSame('Resubmitted', $event->status);
        self::assertNull($event->decision);
        self::assertSame(['nodes_to_resubmit' => ['kyb_documents'], 'reasons' => ['kyb_documents' => 'Blurry']], $event->resubmitInfo);
        self::assertSame('acme-1', $event['vendor_business_id']);
    }

    public function testUserEvents(): void
    {
        $payload = self::ENVELOPE + ['vendor_user_id' => 'customer-1', 'vendor_data' => 'customer-1', 'status' => 'Approved'];
        foreach ([UserStatusUpdatedEvent::class => 'user.status.updated', UserDataUpdatedEvent::class => 'user.data.updated'] as $class => $type) {
            $event = Event::fromPayload($payload + ['webhook_type' => $type]);
            self::assertInstanceOf($class, $event);
            self::assertInstanceOf(UserEvent::class, $event);
            self::assertSame('customer-1', $event->vendorUserId);
            self::assertSame('customer-1', $event->vendorData);
            self::assertSame('Approved', $event->status);
        }
    }

    public function testBusinessEvents(): void
    {
        $payload = self::ENVELOPE + ['vendor_business_id' => 'acme-1', 'vendor_data' => 'acme', 'status' => 'Declined'];
        foreach ([BusinessStatusUpdatedEvent::class => 'business.status.updated', BusinessDataUpdatedEvent::class => 'business.data.updated'] as $class => $type) {
            $event = Event::fromPayload($payload + ['webhook_type' => $type]);
            self::assertInstanceOf($class, $event);
            self::assertInstanceOf(BusinessEvent::class, $event);
            self::assertSame('acme-1', $event->vendorBusinessId);
            self::assertSame('acme', $event->vendorData);
            self::assertSame('Declined', $event->status);
        }
    }

    public function testTransactionEvents(): void
    {
        $payload = self::ENVELOPE + [
            'transaction_id' => '66666666-2222-3333-4444-555555555555',
            'txn_id' => 'txn_2025_0001',
            'status' => 'In Review',
            'score' => 12.5,
            'severity' => 'low',
            'amount' => '1500.00',
            'currency' => 'EUR',
            'direction' => 'outbound',
            'action_required' => false,
        ];
        foreach ([TransactionCreatedEvent::class => 'transaction.created', TransactionStatusUpdatedEvent::class => 'transaction.status.updated'] as $class => $type) {
            $event = Event::fromPayload($payload + ['webhook_type' => $type]);
            self::assertInstanceOf($class, $event);
            self::assertInstanceOf(TransactionEvent::class, $event);
            self::assertSame('66666666-2222-3333-4444-555555555555', $event->transactionId);
            self::assertSame('txn_2025_0001', $event->txnId);
            self::assertSame('In Review', $event->status);
            self::assertSame(12.5, $event->score);
            self::assertSame('low', $event->severity);
            // Money stays a decimal string.
            self::assertSame('1500.00', $event->amount);
            self::assertSame('EUR', $event->currency);
            self::assertSame('outbound', $event->direction);
            self::assertFalse($event->actionRequired);
        }

        $integerScore = Event::fromPayload(['webhook_type' => 'transaction.created', 'score' => 40, 'action_required' => ['type' => 'kyc']]);
        self::assertInstanceOf(TransactionCreatedEvent::class, $integerScore);
        self::assertSame(40, $integerScore->score);
        self::assertSame(['type' => 'kyc'], $integerScore->actionRequired);

        $wrongTypes = Event::fromPayload(['webhook_type' => 'transaction.created', 'score' => '40', 'amount' => 1500, 'action_required' => 'yes']);
        self::assertInstanceOf(TransactionCreatedEvent::class, $wrongTypes);
        self::assertNull($wrongTypes->score);
        self::assertNull($wrongTypes->amount);
        self::assertNull($wrongTypes->actionRequired);
        self::assertSame(1500, $wrongTypes['amount']);
    }

    public function testTravelRuleEvent(): void
    {
        $event = Event::fromPayload(self::ENVELOPE + [
            'webhook_type' => 'travel_rule.status.updated',
            'transaction_id' => '88888888-2222-3333-4444-555555555555',
            'txn_id' => 'txn_2025_0002',
            'travel_rule_status' => 'COMPLETED',
            'rail' => 'TRP',
            'direction' => 'inbound',
        ]);

        self::assertInstanceOf(TravelRuleStatusUpdatedEvent::class, $event);
        self::assertSame('88888888-2222-3333-4444-555555555555', $event->transactionId);
        self::assertSame('txn_2025_0002', $event->txnId);
        self::assertSame('COMPLETED', $event->travelRuleStatus);
        self::assertSame('TRP', $event->rail);
        self::assertSame('inbound', $event->direction);
    }

    public function testKybRegistrySearchResolvedEventNamesItsTypeInEventType(): void
    {
        $event = Event::fromPayload([
            'event_id' => 'e-1',
            'event_type' => 'kyb.registry_search.resolved',
            'request_id' => '5f2d8c1b-7e4a-4b9c-9f0d-3a6e8c2b1d4f',
            'vendor_data' => 'company-1234',
            'metadata' => ['source' => 'onboarding'],
            'search_status' => 'completed',
            'search_resolved' => true,
            'kyb_registry' => ['companies' => [['name' => 'Tesco PLC']]],
            'timestamp' => 1760702400,
            'created_at' => 1760702400,
        ]);

        self::assertInstanceOf(KybRegistrySearchResolvedEvent::class, $event);
        self::assertSame('kyb.registry_search.resolved', $event->webhookType);
        self::assertSame('5f2d8c1b-7e4a-4b9c-9f0d-3a6e8c2b1d4f', $event->requestId);
        self::assertSame('company-1234', $event->vendorData);
        self::assertSame(['source' => 'onboarding'], $event->metadata);
        self::assertSame('completed', $event->searchStatus);
        self::assertTrue($event->searchResolved);
        self::assertSame(['companies' => [['name' => 'Tesco PLC']]], $event->kybRegistry);
    }

    public function testUnknownEventKeepsTheRawPayload(): void
    {
        $payload = self::ENVELOPE + ['webhook_type' => 'transaction_rule_suggestions.created', 'suggestions' => [['rule' => 'r1']]];
        $event = Event::fromPayload($payload);

        self::assertInstanceOf(UnknownEvent::class, $event);
        self::assertSame($payload, $event->toArray());
        self::assertSame($payload, $event->jsonSerialize());
        self::assertSame([['rule' => 'r1']], $event['suggestions']);
    }

    public function testEventsAreReadOnly(): void
    {
        $event = Event::fromPayload(self::ENVELOPE + ['webhook_type' => 'status.updated']);

        $this->expectException(\LogicException::class);
        $event['status'] = 'Approved';
    }

    public function testConcreteEventsBuildFromArrays(): void
    {
        $event = StatusUpdatedEvent::fromArray(['webhook_type' => 'status.updated', 'status' => 'Declined']);

        self::assertSame('Declined', $event->status);
        self::assertNull($event->verifiedWith());
    }
}
