<?php

declare(strict_types=1);

namespace Didit\Tests\Webhooks;

use Didit\Webhooks\Event;
use Didit\Webhooks\Events\BankConnectionConsentExpiringEvent;
use Didit\Webhooks\Events\BankConnectionCreatedEvent;
use Didit\Webhooks\Events\BankConnectionDeletedEvent;
use Didit\Webhooks\Events\BankConnectionEvent;
use Didit\Webhooks\Events\BankConnectionHistoryCompleteEvent;
use Didit\Webhooks\Events\BankConnectionRenewalRequiredEvent;
use Didit\Webhooks\Events\BankConnectionRenewedEvent;
use Didit\Webhooks\Events\BankConnectionRevokedEvent;
use Didit\Webhooks\Events\BankConnectionUpdatedEvent;
use Didit\Webhooks\Events\BankDataUpdatedEvent;
use Didit\Webhooks\Events\BankLinkCancelledEvent;
use Didit\Webhooks\Events\BankLinkCompletedEvent;
use Didit\Webhooks\Events\BankLinkEvent;
use Didit\Webhooks\Events\BankLinkExpiredEvent;
use Didit\Webhooks\Events\BankLinkFailedEvent;
use Didit\Webhooks\Events\BankSyncCompletedEvent;
use Didit\Webhooks\Events\BankSyncFailedEvent;
use Didit\Webhooks\Events\BankSyncRecoveredEvent;
use Didit\Webhooks\EventType;
use PHPUnit\Framework\TestCase;

/**
 * Parses recorded `bank.*` deliveries from the OpenAPI contract.
 *
 * @internal
 */
final class BankEventTest extends TestCase
{
    private const ENVELOPE = [
        'event_id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
        'application_id' => '0b5e8d2c-1f4a-4c3b-9d6e-2a7f8c1b3e5d',
        'environment' => 'sandbox',
        'timestamp' => 1760702400,
        'created_at' => 1760702399,
    ];

    public function testBankLinkEvents(): void
    {
        /** @var array<string, array{class-string<BankLinkEvent>, string, string|null}> $events */
        $events = [
            EventType::BANK_LINK_COMPLETED => [BankLinkCompletedEvent::class, 'completed', '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35'],
            EventType::BANK_LINK_CANCELLED => [BankLinkCancelledEvent::class, 'cancelled', null],
            EventType::BANK_LINK_EXPIRED => [BankLinkExpiredEvent::class, 'expired', null],
            EventType::BANK_LINK_FAILED => [BankLinkFailedEvent::class, 'failed', null],
        ];

        foreach ($events as $type => [$class, $status, $connectionId]) {
            $event = $this->event($type, [
                'link_id' => '0b9c4f1e-6a2d-4e8b-9f31-7c5d2e1a8b40',
                'status' => $status,
                'connection_id' => $connectionId,
                'check_id' => null,
                'error_code' => 'failed' === $status ? 'consent_declined' : null,
            ]);

            self::assertSame($class, $event::class);
            self::assertInstanceOf(BankLinkEvent::class, $event);
            self::assertSame('0b9c4f1e-6a2d-4e8b-9f31-7c5d2e1a8b40', $event->linkId);
            self::assertSame($status, $event->status);
            self::assertSame($connectionId, $event->connectionId);
            self::assertNull($event->checkId);
            self::assertSame('failed' === $status ? 'consent_declined' : null, $event->errorCode);
        }
    }

    public function testBankConnectionCreatedEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_CREATED, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'initial_status' => 'partial',
        ]);

        self::assertInstanceOf(BankConnectionCreatedEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame('partial', $event->initialStatus);
    }

    public function testBankConnectionUpdatedEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_UPDATED, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'status' => 'revocation_pending',
            'revocation_status' => 'pending',
        ]);

        self::assertInstanceOf(BankConnectionUpdatedEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame('revocation_pending', $event->status);
        self::assertSame('pending', $event->revocationStatus);
    }

    public function testBankConnectionConsentExpiringEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_CONSENT_EXPIRING, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'days_left' => 7,
            'consent_expires_at' => '2027-05-20T12:04:11Z',
            'renew_by' => '2027-05-20T12:04:11Z',
        ]);

        self::assertInstanceOf(BankConnectionConsentExpiringEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame(7, $event->daysLeft);
        self::assertSame('2027-05-20T12:04:11+00:00', $event->consentExpiresAt?->format(\DATE_ATOM));
        self::assertSame('2027-05-20T12:04:11+00:00', $event->renewBy?->format(\DATE_ATOM));
    }

    public function testBankConnectionRenewalRequiredEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_RENEWAL_REQUIRED, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'status' => 'renewal_required',
            'reason' => 'bank_requires_sign_in',
            'renew_by' => '2027-05-20T12:04:11Z',
            'renewable_until' => '2027-06-19T12:04:11Z',
        ]);

        self::assertInstanceOf(BankConnectionRenewalRequiredEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame('renewal_required', $event->status);
        self::assertSame('bank_requires_sign_in', $event->reason);
        self::assertSame('2027-05-20T12:04:11+00:00', $event->renewBy?->format(\DATE_ATOM));
        self::assertSame('2027-06-19T12:04:11+00:00', $event->renewableUntil?->format(\DATE_ATOM));
    }

    public function testBankConnectionRenewedEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_RENEWED, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'status' => 'active',
            'consent_version' => 2,
            'link_id' => '0b9c4f1e-6a2d-4e8b-9f31-7c5d2e1a8b40',
            'consent_expires_at' => '2027-05-20T12:04:11Z',
        ]);

        self::assertInstanceOf(BankConnectionRenewedEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame('active', $event->status);
        self::assertSame(2, $event->consentVersion);
        self::assertSame('0b9c4f1e-6a2d-4e8b-9f31-7c5d2e1a8b40', $event->linkId);
        self::assertSame('2027-05-20T12:04:11+00:00', $event->consentExpiresAt?->format(\DATE_ATOM));
    }

    public function testBankConnectionRevokedEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_REVOKED, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'status' => 'revoked',
            'ended_by' => 'bank',
            'status_reason' => 'revoked_by_bank',
            'ended_at' => '2026-11-21T15:10:00Z',
            'retain_until' => '2026-12-21T15:10:00Z',
        ]);

        self::assertInstanceOf(BankConnectionRevokedEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame('revoked', $event->status);
        self::assertSame('bank', $event->endedBy);
        self::assertSame('revoked_by_bank', $event->statusReason);
        self::assertSame('2026-11-21T15:10:00+00:00', $event->endedAt?->format(\DATE_ATOM));
        self::assertSame('2026-12-21T15:10:00+00:00', $event->retainUntil?->format(\DATE_ATOM));
    }

    public function testBankConnectionDeletedEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_DELETED, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'status' => 'deleted',
            'erasure_status' => 'erased',
            'reason' => 'deleted_by_customer',
            'erased_at' => '2026-11-21T15:10:00Z',
        ]);

        self::assertInstanceOf(BankConnectionDeletedEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame('deleted', $event->status);
        self::assertSame('erased', $event->erasureStatus);
        self::assertSame('deleted_by_customer', $event->reason);
        self::assertSame('2026-11-21T15:10:00+00:00', $event->erasedAt?->format(\DATE_ATOM));
    }

    public function testBankConnectionHistoryCompleteEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_HISTORY_COMPLETE, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'status' => 'partial',
        ]);

        self::assertInstanceOf(BankConnectionHistoryCompleteEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame('partial', $event->status);
    }

    public function testBankDataUpdatedEvent(): void
    {
        $event = $this->event(EventType::BANK_DATA_UPDATED, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'sync_id' => 'd4a9e7b2-1c58-4f03-96ab-5e2d8c7f0b14',
            'trigger' => 'scheduled',
            'accounts' => [
                [
                    'account_id' => 'a71f0c2e-5d38-4b96-a1c4-3e9f8d2b7a10',
                    'added' => 3,
                    'modified' => 2,
                    'removed' => 1,
                ],
            ],
            'balances_changed' => true,
        ]);

        self::assertInstanceOf(BankDataUpdatedEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame('d4a9e7b2-1c58-4f03-96ab-5e2d8c7f0b14', $event->syncId);
        self::assertSame('scheduled', $event->trigger);
        self::assertTrue($event->balancesChanged);
        $accounts = $event->accounts ?? [];
        self::assertCount(1, $accounts);
        self::assertSame('a71f0c2e-5d38-4b96-a1c4-3e9f8d2b7a10', $accounts[0]->accountId);
        self::assertSame(3, $accounts[0]->added);
        self::assertSame(2, $accounts[0]->modified);
        self::assertSame(1, $accounts[0]->removed);
    }

    public function testBankSyncCompletedEvent(): void
    {
        $event = $this->event(EventType::BANK_SYNC_COMPLETED, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'sync_id' => 'd4a9e7b2-1c58-4f03-96ab-5e2d8c7f0b14',
            'trigger' => 'requested',
            'outcome' => 'partial',
            'changed' => true,
        ]);

        self::assertInstanceOf(BankSyncCompletedEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame('d4a9e7b2-1c58-4f03-96ab-5e2d8c7f0b14', $event->syncId);
        self::assertSame('requested', $event->trigger);
        self::assertSame('partial', $event->outcome);
        self::assertTrue($event->changed);
    }

    public function testBankSyncFailedEvent(): void
    {
        $event = $this->event(EventType::BANK_SYNC_FAILED, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'sync_id' => 'd4a9e7b2-1c58-4f03-96ab-5e2d8c7f0b14',
            'error_code' => 'bank_unavailable',
            'failing_since' => '2026-11-20T09:00:00Z',
        ]);

        self::assertInstanceOf(BankSyncFailedEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame('d4a9e7b2-1c58-4f03-96ab-5e2d8c7f0b14', $event->syncId);
        self::assertSame('bank_unavailable', $event->errorCode);
        self::assertSame('2026-11-20T09:00:00+00:00', $event->failingSince?->format(\DATE_ATOM));
    }

    public function testBankSyncRecoveredEvent(): void
    {
        $event = $this->event(EventType::BANK_SYNC_RECOVERED, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'sync_id' => 'd4a9e7b2-1c58-4f03-96ab-5e2d8c7f0b14',
        ]);

        self::assertInstanceOf(BankSyncRecoveredEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame('d4a9e7b2-1c58-4f03-96ab-5e2d8c7f0b14', $event->syncId);
    }

    public function testLegacyNestedDataIsRead(): void
    {
        $event = Event::fromPayload(self::ENVELOPE + [
            'webhook_type' => EventType::BANK_CONNECTION_CREATED,
            'data' => [
                'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
                'initial_status' => 'succeeded',
            ],
        ]);

        self::assertInstanceOf(BankConnectionEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function event(string $type, array $fields): Event
    {
        return Event::fromPayload(self::ENVELOPE + ['webhook_type' => $type] + $fields);
    }
}
