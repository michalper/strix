<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Dto\PaymentWebhookPayload;
use App\Entity\WebhookEvent;

final class WebhookEventTestFactory
{
    public static function create(string $eventId = 'evt_test_1', string $orderId = 'ORD-1'): WebhookEvent
    {
        $json = json_encode([
            'event' => 'charge.successful',
            'event_id' => $eventId,
            'timestamp' => 1718029200,
            'livemode' => true,
            'data' => [
                'transaction' => [
                    'id' => 'txn_1',
                    'amount' => ['value' => 1000, 'currency' => 'PLN'],
                    'status' => 'COMPLETED',
                ],
                'customer' => ['id' => 'cus_1', 'email' => 'test@example.com'],
                'metadata' => ['order_id' => $orderId, 'store_id' => 'store_1'],
            ],
        ], JSON_THROW_ON_ERROR);

        return WebhookEvent::fromPayload(PaymentWebhookPayload::fromRawJson($json));
    }
}
