<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\MessengerFailureListener;
use App\Message\SendPaymentNotification;
use App\Repository\WebhookEventRepository;
use App\Tests\Support\WebhookEventTestFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class MessengerFailureListenerTest extends TestCase
{
    public function testExhaustedRetriesMarksDeadLetter(): void
    {
        $event = WebhookEventTestFactory::create();
        $repository = $this->createMock(WebhookEventRepository::class);
        $repository->method('findOneByEventId')->willReturn($event);
        $repository->expects(self::once())->method('markDeadLetter')->with($event, self::callback(static fn ($value): bool => is_string($value)));

        $workerEvent = new WorkerMessageFailedEvent(
            new Envelope(new SendPaymentNotification($event->getEventId())),
            'async',
            new \RuntimeException('boom'),
        );
        // willRetry() is false unless something explicitly calls setForRetry() -
        // which is exactly the "retries exhausted" case this listener must act on.

        (new MessengerFailureListener($repository, new NullLogger()))($workerEvent);
    }

    public function testStillRetryingDoesNotMarkDeadLetter(): void
    {
        $event = WebhookEventTestFactory::create();
        $repository = $this->createMock(WebhookEventRepository::class);
        $repository->expects(self::never())->method('markDeadLetter');

        $workerEvent = new WorkerMessageFailedEvent(
            new Envelope(new SendPaymentNotification($event->getEventId())),
            'async',
            new \RuntimeException('boom'),
        );
        $workerEvent->setForRetry();

        (new MessengerFailureListener($repository, new NullLogger()))($workerEvent);
    }

    public function testUnrelatedMessageTypeIsIgnored(): void
    {
        $repository = $this->createMock(WebhookEventRepository::class);
        $repository->expects(self::never())->method('findOneByEventId');
        $repository->expects(self::never())->method('markDeadLetter');

        $workerEvent = new WorkerMessageFailedEvent(
            new Envelope(new \stdClass()),
            'async',
            new \RuntimeException('boom'),
        );

        (new MessengerFailureListener($repository, new NullLogger()))($workerEvent);
    }

    public function testUnknownEventIdIsIgnored(): void
    {
        $repository = $this->createMock(WebhookEventRepository::class);
        $repository->method('findOneByEventId')->willReturn(null);
        $repository->expects(self::never())->method('markDeadLetter');

        $workerEvent = new WorkerMessageFailedEvent(
            new Envelope(new SendPaymentNotification('evt_does_not_exist')),
            'async',
            new \RuntimeException('boom'),
        );

        (new MessengerFailureListener($repository, new NullLogger()))($workerEvent);
    }
}
