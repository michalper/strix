<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendPaymentNotification;
use App\Notification\NotificationChannelInterface;
use App\Notification\NotificationPayload;
use App\Repository\WebhookEventRepository;
use App\ValueObject\Money;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[WithMonologChannel('webhook')]
#[AsMessageHandler]
final readonly class SendPaymentNotificationHandler
{
    public function __construct(
        private WebhookEventRepository $repository,
        private NotificationChannelInterface $channel,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(SendPaymentNotification $message): void
    {
        $event = $this->repository->findOneByEventId($message->eventId);

        if (null === $event) {
            // Nothing sensible to retry towards; the webhook row is the source of truth.
            throw new UnrecoverableMessageHandlingException(\sprintf('Webhook event "%s" not found, skipping.', $message->eventId));
        }

        $payload = new NotificationPayload(
            eventId: $event->getEventId(),
            orderId: $event->getOrderId(),
            storeId: $event->getStoreId(),
            customerEmail: $event->getCustomerEmail(),
            amount: new Money($event->getAmountValue(), $event->getAmountCurrency()),
        );

        try {
            $this->channel->send($payload);
        } catch (ClientExceptionInterface $e) {
            $this->repository->incrementRetry($event, $e->getMessage());
            $statusCode = $e->getResponse()->getStatusCode();

            // 408/429 are rate-limit/timeout signals from the provider, not a
            // permanently malformed request - they need the same backoff-and-retry
            // treatment as a 5xx, not an immediate drop to the DLQ.
            if (\in_array($statusCode, [408, 429], true)) {
                $this->logger->warning('Notification delivery rate-limited or timed out, will retry.', [
                    'event_id' => $event->getEventId(),
                    'status_code' => $statusCode,
                    'retry_count' => $event->getRetryCount(),
                ]);

                throw $e;
            }

            // Any other 4xx: our request was rejected outright — not transient, don't retry.
            throw new UnrecoverableMessageHandlingException($e->getMessage(), $statusCode, previous: $e);
        } catch (ServerExceptionInterface|TransportExceptionInterface $e) {
            // 5xx or network/timeout: transient. Rethrow so Messenger's retry_strategy
            // (exponential backoff) takes over; it routes to the DLQ once exhausted.
            $this->repository->incrementRetry($event, $e->getMessage());

            $this->logger->warning('Notification delivery failed, will retry.', [
                'event_id' => $event->getEventId(),
                'retry_count' => $event->getRetryCount(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->repository->markNotified($event);
    }
}
