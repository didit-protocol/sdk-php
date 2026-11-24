<?php

declare(strict_types=1);

namespace Didit\Tests\Webhooks;

use Didit\Core\Hydrate;
use Didit\Webhooks\Event;
use Didit\Webhooks\Events\BankConnectionConsentExpiringEvent;
use Didit\Webhooks\Events\BankConnectionCreatedEvent;
use Didit\Webhooks\Events\BankConnectionDeletedEvent;
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
use Didit\Webhooks\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * Parses recorded `bank.*` deliveries from the backend through the
 * public `Webhook::verify` path, from signed bodies.
 *
 * @internal
 */
final class BankEventTest extends TestCase
{
    public function testBankLinkEvents(): void
    {
        /** @var array<string, class-string<BankLinkEvent>> $events */
        $events = [
            EventType::BANK_LINK_COMPLETED => BankLinkCompletedEvent::class,
            EventType::BANK_LINK_CANCELLED => BankLinkCancelledEvent::class,
            EventType::BANK_LINK_EXPIRED => BankLinkExpiredEvent::class,
            EventType::BANK_LINK_FAILED => BankLinkFailedEvent::class,
        ];

        foreach ($events as $type => $class) {
            $event = $this->event($type);
            self::assertInstanceOf($class, $event);
            $fields = Hydrate::assoc($event->get('data')) ?? [];
            self::assertSame($fields['link_id'], $event->linkId);
            self::assertSame($fields['status'], $event->status);
            self::assertSame($fields['connection_id'], $event->connectionId);
            self::assertSame($fields['check_id'], $event->checkId);
            self::assertSame($fields['error_code'], $event->errorCode);
        }
    }

    public function testBankConnectionCreatedEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_CREATED);

        self::assertInstanceOf(BankConnectionCreatedEvent::class, $event);
        self::assertSame('cccccccc-0000-4000-8000-000000000001', $event->connectionId);
        self::assertSame('succeeded', $event->initialStatus);
    }

    public function testBankConnectionUpdatedEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_UPDATED);

        self::assertInstanceOf(BankConnectionUpdatedEvent::class, $event);
        self::assertSame('cccccccc-0000-4000-8000-000000000001', $event->connectionId);
        self::assertSame('active', $event->status);
        self::assertSame('pending', $event->revocationStatus);
    }

    public function testBankConnectionConsentExpiringEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_CONSENT_EXPIRING);

        self::assertInstanceOf(BankConnectionConsentExpiringEvent::class, $event);
        self::assertSame('cccccccc-0000-4000-8000-000000000001', $event->connectionId);
        self::assertSame(7, $event->daysLeft);
        self::assertSame('2026-11-29T09:00:00+00:00', $event->consentExpiresAt?->format(\DATE_ATOM));
        self::assertSame('2026-11-29T09:00:00+00:00', $event->renewBy?->format(\DATE_ATOM));
    }

    public function testBankConnectionRenewalRequiredEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_RENEWAL_REQUIRED);

        self::assertInstanceOf(BankConnectionRenewalRequiredEvent::class, $event);
        self::assertSame('cccccccc-0000-4000-8000-000000000001', $event->connectionId);
        self::assertSame('renewal_required', $event->status);
        self::assertSame('consent_expired', $event->reason);
        self::assertSame('2026-11-29T09:00:00+00:00', $event->renewBy?->format(\DATE_ATOM));
        self::assertSame('2026-12-29T09:00:00+00:00', $event->renewableUntil?->format(\DATE_ATOM));
    }

    public function testBankConnectionRenewedEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_RENEWED);

        self::assertInstanceOf(BankConnectionRenewedEvent::class, $event);
        self::assertSame('cccccccc-0000-4000-8000-000000000001', $event->connectionId);
        self::assertSame('active', $event->status);
        self::assertSame(2, $event->consentVersion);
        self::assertSame('bbbbbbbb-0000-4000-8000-000000000005', $event->linkId);
        self::assertSame('2027-11-29T09:00:00+00:00', $event->consentExpiresAt?->format(\DATE_ATOM));
    }

    public function testBankConnectionRevokedEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_REVOKED);

        self::assertInstanceOf(BankConnectionRevokedEvent::class, $event);
        self::assertSame('cccccccc-0000-4000-8000-000000000001', $event->connectionId);
        self::assertSame('revoked', $event->status);
        self::assertSame('bank', $event->endedBy);
        self::assertSame('revoked_by_bank', $event->statusReason);
        self::assertSame('2026-11-22T09:00:00+00:00', $event->endedAt?->format(\DATE_ATOM));
        self::assertSame('2027-02-20T09:00:00+00:00', $event->retainUntil?->format(\DATE_ATOM));
    }

    public function testBankConnectionDeletedEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_DELETED);

        self::assertInstanceOf(BankConnectionDeletedEvent::class, $event);
        self::assertSame('cccccccc-0000-4000-8000-000000000001', $event->connectionId);
        self::assertSame('deleted', $event->status);
        self::assertSame('erased', $event->erasureStatus);
        self::assertSame('deleted_by_customer', $event->reason);
        self::assertSame('2026-11-22T09:00:00+00:00', $event->erasedAt?->format(\DATE_ATOM));
    }

    public function testBankConnectionHistoryCompleteEvent(): void
    {
        $event = $this->event(EventType::BANK_CONNECTION_HISTORY_COMPLETE);

        self::assertInstanceOf(BankConnectionHistoryCompleteEvent::class, $event);
        self::assertSame('cccccccc-0000-4000-8000-000000000001', $event->connectionId);
        self::assertSame('complete', $event->status);
    }

    public function testBankDataUpdatedEvent(): void
    {
        $event = $this->event(EventType::BANK_DATA_UPDATED);

        self::assertInstanceOf(BankDataUpdatedEvent::class, $event);
        self::assertSame('cccccccc-0000-4000-8000-000000000001', $event->connectionId);
        self::assertSame('eeeeeeee-0000-4000-8000-000000000001', $event->syncId);
        self::assertSame('scheduled', $event->trigger);
        self::assertTrue($event->balancesChanged);
        $accounts = $event->accounts ?? [];
        self::assertCount(1, $accounts);
        self::assertSame('ffffffff-0000-4000-8000-000000000001', $accounts[0]->accountId);
        self::assertSame(3, $accounts[0]->added);
        self::assertSame(1, $accounts[0]->modified);
        self::assertSame(0, $accounts[0]->removed);
    }

    public function testBankSyncCompletedEvent(): void
    {
        $event = $this->event(EventType::BANK_SYNC_COMPLETED);

        self::assertInstanceOf(BankSyncCompletedEvent::class, $event);
        self::assertSame('cccccccc-0000-4000-8000-000000000001', $event->connectionId);
        self::assertSame('eeeeeeee-0000-4000-8000-000000000001', $event->syncId);
        self::assertSame('scheduled', $event->trigger);
        self::assertSame('partial', $event->outcome);
        self::assertTrue($event->changed);
    }

    public function testBankSyncFailedEvent(): void
    {
        $event = $this->event(EventType::BANK_SYNC_FAILED);

        self::assertInstanceOf(BankSyncFailedEvent::class, $event);
        self::assertSame('cccccccc-0000-4000-8000-000000000001', $event->connectionId);
        self::assertSame('eeeeeeee-0000-4000-8000-000000000001', $event->syncId);
        self::assertSame('bank_unavailable', $event->errorCode);
        self::assertSame('2026-11-21T09:00:00+00:00', $event->failingSince?->format(\DATE_ATOM));
    }

    public function testBankSyncRecoveredEvent(): void
    {
        $event = $this->event(EventType::BANK_SYNC_RECOVERED);

        self::assertInstanceOf(BankSyncRecoveredEvent::class, $event);
        self::assertSame('cccccccc-0000-4000-8000-000000000001', $event->connectionId);
        self::assertSame('eeeeeeee-0000-4000-8000-000000000002', $event->syncId);
    }

    /**
     * The contract puts a connection event's fields next to the envelope, with
     * no `data` wrapper: a body that nests them there must not fill the typed
     * properties. The payload itself stays readable through `get()`.
     */
    public function testConnectionEventsDoNotReadANestedDataObject(): void
    {
        $event = Event::fromPayload([
            'webhook_type' => EventType::BANK_CONNECTION_UPDATED,
            'data' => ['connection_id' => 'nested', 'status' => 'active', 'revocation_status' => 'pending'],
        ]);

        self::assertInstanceOf(BankConnectionUpdatedEvent::class, $event);
        self::assertNull($event->connectionId);
        self::assertNull($event->status);
        self::assertNull($event->revocationStatus);

        $data = Hydrate::assoc($event->get('data')) ?? [];
        self::assertSame('active', $data['status'] ?? null);
    }

    /**
     * Replay both backend-signed deliveries from sdk-java PR #108 at 412c4a4.
     * The rotation case uses the previous secret with the original headers.
     */
    private function event(string $type): Event
    {
        $json = file_get_contents(__DIR__ . '/fixtures/bank-deliveries.json');
        self::assertIsString($json);
        /** @var array<string, array{body: string, headers: array<string, string>, secret: string, previous_secret: ?string, now: int}> $deliveries */
        $deliveries = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        $name = str_replace('.', '_', $type);
        $current = $deliveries[$name];
        $event = Webhook::verify($current['body'], $current['headers'], $current['secret'], now: $current['now']);
        self::assertSame(Webhook::HEADER_SIGNATURE, $event->verifiedWith());
        self::assertTrue($event->bodyAuthenticated());
        self::assertSame($type, $event->webhookType);

        $rotation = $deliveries[$name . '_rotation'];
        self::assertNotNull($rotation['previous_secret']);
        $rotated = Webhook::verify($rotation['body'], $rotation['headers'], $rotation['previous_secret'], now: $rotation['now']);
        self::assertSame(Webhook::HEADER_SIGNATURE_PREVIOUS, $rotated->verifiedWith());
        self::assertTrue($rotated->bodyAuthenticated());
        self::assertSame($event::class, $rotated::class);
        self::assertSame($event->toArray(), $rotated->toArray());

        return $event;
    }
}
