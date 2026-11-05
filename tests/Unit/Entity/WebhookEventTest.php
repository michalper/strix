<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Enum\WebhookProcessingStatus;
use App\Tests\Support\WebhookEventTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class WebhookEventTest extends TestCase
{
    public function testFromPayloadSetsExpectedFieldsAndPendingStatus(): void
    {
        $event = WebhookEventTestFactory::create('evt_1', 'ORD-1');

        self::assertNull($event->getId());
        self::assertSame('evt_1', $event->getEventId());
        self::assertSame('charge.successful', $event->getEventType());
        self::assertSame('txn_1', $event->getTransactionId());
        self::assertSame(1000, $event->getAmountValue());
        self::assertSame('PLN', $event->getAmountCurrency());
        self::assertSame('test@example.com', $event->getCustomerEmail());
        self::assertSame('ORD-1', $event->getOrderId());
        self::assertSame('store_1', $event->getStoreId());
        self::assertNull($event->getChannel());
        self::assertSame(WebhookProcessingStatus::Pending, $event->getProcessingStatus());
        self::assertSame(0, $event->getRetryCount());
        self::assertNull($event->getLastError());
    }

    public function testMarkNotifiedTransitionsStatus(): void
    {
        $event = WebhookEventTestFactory::create();

        $event->markNotified();

        self::assertSame(WebhookProcessingStatus::Notified, $event->getProcessingStatus());
    }

    public function testIncrementRetryTracksCountAndLastError(): void
    {
        $event = WebhookEventTestFactory::create();

        $event->incrementRetry('first failure');
        self::assertSame(1, $event->getRetryCount());
        self::assertSame('first failure', $event->getLastError());

        $event->incrementRetry('second failure');
        self::assertSame(2, $event->getRetryCount());
        self::assertSame('second failure', $event->getLastError());
    }

    public function testMarkDeadLetterTransitionsStatusAndRecordsError(): void
    {
        $event = WebhookEventTestFactory::create();

        $event->markDeadLetter('exhausted retries');

        self::assertSame(WebhookProcessingStatus::DeadLetter, $event->getProcessingStatus());
        self::assertSame('exhausted retries', $event->getLastError());
    }
}
