<?php

declare(strict_types=1);

namespace App\Notification;

use App\ValueObject\Money;

final readonly class NotificationPayload
{
    public function __construct(
        public string $eventId,
        public string $orderId,
        public string $storeId,
        public string $customerEmail,
        public Money $amount,
    ) {
    }
}
