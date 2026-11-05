<?php

declare(strict_types=1);

namespace App\Notification;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MockHttpNotificationChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $notificationUrl,
    ) {
    }

    public function send(NotificationPayload $payload): void
    {
        $response = $this->httpClient->request('POST', $this->notificationUrl, [
            'json' => [
                'event_id' => $payload->eventId,
                'order_id' => $payload->orderId,
                'store_id' => $payload->storeId,
                'customer_email' => $payload->customerEmail,
                'amount' => [
                    'value' => $payload->amount->minorUnits,
                    'currency' => $payload->amount->currency,
                ],
            ],
        ]);

        // getStatusCode() alone does not throw; reading the content is what makes
        // Symfony's HttpClient raise ClientExceptionInterface/ServerExceptionInterface/
        // TransportExceptionInterface for 4xx/5xx/timeout responses.
        $response->getContent();
    }
}
