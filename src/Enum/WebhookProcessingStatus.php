<?php

declare(strict_types=1);

namespace App\Enum;

enum WebhookProcessingStatus: string
{
    case Pending = 'PENDING';
    case Notified = 'NOTIFIED';
    case DeadLetter = 'DEAD_LETTER';
}
