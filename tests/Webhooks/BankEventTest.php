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

    public function testBankConnectionEvents(): void
    {
        /** @var array<string, class-string<BankConnectionEvent>> $events */
        $events = [
            EventType::BANK_CONNECTION_CREATED => BankConnectionCreatedEvent::class,
            EventType::BANK_CONNECTION_UPDATED => BankConnectionUpdatedEvent::class,
            EventType::BANK_CONNECTION_RENEWAL_REQUIRED => BankConnectionRenewalRequiredEvent::class,
            EventType::BANK_CONNECTION_RENEWED => BankConnectionRenewedEvent::class,
            EventType::BANK_CONNECTION_HISTORY_COMPLETE => BankConnectionHistoryCompleteEvent::class,
        ];

        foreach ($events as $type => $class) {
            $event = $this->event($type, [
                'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
                'status' => 'active',
                'status_reason' => 'bank_requires_sign_in',
            ]);

            self::assertSame($class, $event::class);
            self::assertInstanceOf(BankConnectionEvent::class, $event);
            self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
            self::assertSame('active', $event->status);
            self::assertSame('bank_requires_sign_in', $event->statusReason);
        }
    }

    public function testBankConnectionConsentExpiringEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_CONSENT_EXPIRING, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'days_left' => 7,
            'expires_at' => '2027-05-20T12:04:11Z',
            'renew_url' => 'https://verify.didit.me/bank/access/sample',
        ]);

        self::assertInstanceOf(BankConnectionConsentExpiringEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame(7, $event->daysLeft);
        self::assertSame('2027-05-20T12:04:11+00:00', $event->expiresAt?->format(\DATE_ATOM));
        self::assertSame('https://verify.didit.me/bank/access/sample', $event->renewUrl);
    }

    public function testBankConnectionRevokedEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_REVOKED, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'status' => 'revoked',
            'ended_by' => 'bank',
        ]);

        self::assertInstanceOf(BankConnectionRevokedEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame('revoked', $event->status);
        self::assertSame('bank', $event->endedBy);
    }

    public function testBankConnectionDeletedEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_DELETED, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'erasure_status' => 'erased',
        ]);

        self::assertInstanceOf(BankConnectionDeletedEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame('erased', $event->erasureStatus);
    }

    public function testBankDataUpdatedEvent(): void
    {
        $event = $this->event(EventType::BANK_DATA_UPDATED, [
            'connection_id' => '5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35',
            'sync_id' => 'd4a9e7b2-1c58-4f03-96ab-5e2d8c7f0b14',
            'account_ids' => [
                'a71f0c2e-5d38-4b96-a1c4-3e9f8d2b7a10',
                'c3d84e19-2a7b-4f60-b5e1-9a0c6d3f8e72',
            ],
            'added' => 3,
            'modified' => 2,
            'removed' => 1,
            'balances_changed' => true,
        ]);

        self::assertInstanceOf(BankDataUpdatedEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertSame('d4a9e7b2-1c58-4f03-96ab-5e2d8c7f0b14', $event->syncId);
        self::assertSame([
            'a71f0c2e-5d38-4b96-a1c4-3e9f8d2b7a10',
            'c3d84e19-2a7b-4f60-b5e1-9a0c6d3f8e72',
        ], $event->accountIds);
        self::assertSame(3, $event->added);
        self::assertSame(2, $event->modified);
        self::assertSame(1, $event->removed);
        self::assertTrue($event->balancesChanged);
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
            'sync_id' => null,
            'error_code' => 'bank_unavailable',
        ]);

        self::assertInstanceOf(BankSyncFailedEvent::class, $event);
        self::assertSame('5e1d2a70-3b94-4c1f-8a6e-d2f07b9c1e35', $event->connectionId);
        self::assertNull($event->syncId);
        self::assertSame('bank_unavailable', $event->errorCode);
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

    /**
     * @param array<string, mixed> $data
     */
    private function event(string $type, array $data): Event
    {
        return Event::fromPayload(self::ENVELOPE + ['webhook_type' => $type, 'data' => $data]);
    }
}
