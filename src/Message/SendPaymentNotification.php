<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SendPaymentNotification
{
    public function __construct(
        public string $eventId,
    ) {}
}
