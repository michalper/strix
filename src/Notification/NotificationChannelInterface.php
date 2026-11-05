<?php

declare(strict_types=1);

namespace App\Notification;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Strategy for delivering a payment notification to an external service. A single
 * mocked implementation exists today, but adding a real channel later (Slack, email,
 * SMS...) is a new class implementing this interface plus a service alias change —
 * no change to SendPaymentNotificationHandler. This is the concrete counter-argument
 * to the client's "5 notification channels" request (see EMAIL_TO_CLIENT.md).
 */
interface NotificationChannelInterface
{
    /**
     * @throws ExceptionInterface on any transport/HTTP failure (4xx, 5xx, timeout)
     */
    public function send(NotificationPayload $payload): void;
}
