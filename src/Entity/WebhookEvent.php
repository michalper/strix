<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\PaymentWebhookPayload;
use App\Enum\WebhookProcessingStatus;
use App\Repository\WebhookEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebhookEventRepository::class)]
#[ORM\Table(name: 'webhook_events')]
#[ORM\Index(name: 'idx_webhook_events_order_id', columns: ['order_id'])]
#[ORM\Index(name: 'idx_webhook_events_processing_status', columns: ['processing_status'])]
class WebhookEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $eventId;

    #[ORM\Column(length: 64)]
    private string $eventType;

    #[ORM\Column]
    private bool $livemode;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 64)]
    private string $transactionId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $providerReference = null;

    #[ORM\Column]
    private int $amountValue;

    #[ORM\Column(length: 3)]
    private string $amountCurrency;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $paymentMethodType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $paymentMethodIssuer = null;

    #[ORM\Column(length: 32)]
    private string $transactionStatus;

    #[ORM\Column(length: 64)]
    private string $customerId;

    #[ORM\Column(length: 255)]
    private string $customerEmail;

    #[ORM\Column(length: 64)]
    private string $orderId;

    #[ORM\Column(length: 64)]
    private string $storeId;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $channel = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'jsonb')]
    private array $rawPayload;

    #[ORM\Column(length: 16, enumType: WebhookProcessingStatus::class)]
    private WebhookProcessingStatus $processingStatus;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $retryCount = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $notifiedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    private function __construct() {}

    public static function fromPayload(PaymentWebhookPayload $payload): self
    {
        $event = new self();
        $event->eventId = $payload->eventId;
        $event->eventType = $payload->eventType;
        $event->livemode = $payload->livemode;
        $event->occurredAt = $payload->occurredAt;
        $event->transactionId = $payload->transactionId;
        $event->providerReference = $payload->providerReference;
        $event->amountValue = $payload->amount->minorUnits;
        $event->amountCurrency = $payload->amount->currency;
        $event->paymentMethodType = $payload->paymentMethodType;
        $event->paymentMethodIssuer = $payload->paymentMethodIssuer;
        $event->transactionStatus = $payload->transactionStatus;
        $event->customerId = $payload->customerId;
        $event->customerEmail = $payload->customerEmail;
        $event->orderId = $payload->orderId;
        $event->storeId = $payload->storeId;
        $event->channel = $payload->channel;
        $event->rawPayload = $payload->rawPayload;
        $event->processingStatus = WebhookProcessingStatus::Pending;
        $event->receivedAt = new \DateTimeImmutable();

        return $event;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    public function getAmountValue(): int
    {
        return $this->amountValue;
    }

    public function getAmountCurrency(): string
    {
        return $this->amountCurrency;
    }

    public function getCustomerEmail(): string
    {
        return $this->customerEmail;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getStoreId(): string
    {
        return $this->storeId;
    }

    public function getChannel(): ?string
    {
        return $this->channel;
    }

    public function getProcessingStatus(): WebhookProcessingStatus
    {
        return $this->processingStatus;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function markNotified(): void
    {
        $this->processingStatus = WebhookProcessingStatus::Notified;
        $this->notifiedAt = new \DateTimeImmutable();
    }

    public function incrementRetry(string $error): void
    {
        ++$this->retryCount;
        $this->lastError = $error;
    }

    public function markDeadLetter(string $error): void
    {
        $this->processingStatus = WebhookProcessingStatus::DeadLetter;
        $this->lastError = $error;
        $this->failedAt = new \DateTimeImmutable();
    }
}
