<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\PaymentWebhookPayload;
use App\Entity\WebhookEvent;
use App\Enum\WebhookProcessingStatus;
use App\Exception\InvalidWebhookPayloadException;
use App\Message\SendPaymentNotification;
use App\Repository\WebhookEventRepository;
use App\Security\WebhookSignatureVerifier;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[WithMonologChannel('webhook')]
final readonly class PaymentWebhookController
{
    public function __construct(
        private WebhookSignatureVerifier $signatureVerifier,
        private WebhookEventRepository $repository,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {}

    #[Route('/api/v1/webhooks/payment', name: 'webhook_payment', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        if (!$this->signatureVerifier->verify($rawBody, $request->headers->get('X-Signature'))) {
            $this->logger->warning('Webhook rejected: missing or invalid signature.');

            return new JsonResponse(['error' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = PaymentWebhookPayload::fromRawJson($rawBody);
        } catch (InvalidWebhookPayloadException $e) {
            $this->logger->warning('Webhook rejected: {error_code}', [
                'error_code' => $e->getErrorCode(),
                'fields' => $e->getFieldErrors(),
            ]);

            $body = ['error' => $e->getErrorCode()];
            if ([] !== $e->getFieldErrors()) {
                $body['fields'] = $e->getFieldErrors();
            }

            return new JsonResponse($body, Response::HTTP_BAD_REQUEST);
        }

        $event = WebhookEvent::fromPayload($payload);
        $isNew = $this->repository->insertIfNotExists($event);

        if (!$isNew) {
            // If the original request's dispatch() below never reached RabbitMQ (e.g. a
            // transient outage right after the insert committed), the row is stuck
            // PENDING with no notification ever sent and no other recovery path. A
            // provider's retry of the same event_id is the only signal we get for that -
            // use it to redispatch instead of just re-confirming "already processed".
            $existing = $this->repository->findOneByEventId($event->getEventId());
            if (null !== $existing && WebhookProcessingStatus::Pending === $existing->getProcessingStatus()) {
                $this->messageBus->dispatch(new SendPaymentNotification($existing->getEventId()));
                $this->logger->info('Duplicate webhook still pending; re-dispatched notification.', ['event_id' => $existing->getEventId()]);
            } else {
                $this->logger->info('Duplicate webhook ignored.', ['event_id' => $event->getEventId()]);
            }

            return new JsonResponse(['status' => 'already_processed', 'event_id' => $event->getEventId()], Response::HTTP_OK);
        }

        $this->messageBus->dispatch(new SendPaymentNotification($event->getEventId()));

        return new JsonResponse(['status' => 'accepted', 'event_id' => $event->getEventId()], Response::HTTP_ACCEPTED);
    }
}
