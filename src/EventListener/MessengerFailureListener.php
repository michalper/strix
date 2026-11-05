<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Message\SendPaymentNotification;
use App\Repository\WebhookEventRepository;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[WithMonologChannel('webhook')]
#[AsEventListener(event: WorkerMessageFailedEvent::class)]
final class MessengerFailureListener
{
    public function __construct(
        private readonly WebhookEventRepository $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();

        if (!$message instanceof SendPaymentNotification) {
            return;
        }

        $webhookEvent = $this->repository->findOneByEventId($message->eventId);

        if (null === $webhookEvent) {
            return;
        }

        $error = $event->getThrowable()->getMessage();
        $this->repository->markDeadLetter($webhookEvent, $error);

        $this->logger->error('Payment notification exhausted retries, moved to dead letter.', [
            'event_id' => $webhookEvent->getEventId(),
            'retry_count' => $webhookEvent->getRetryCount(),
            'exception' => $event->getThrowable()::class,
            'error' => $error,
        ]);
    }
}
