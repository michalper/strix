<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WebhookEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Not final: unit tests mock this repository to isolate SendPaymentNotificationHandler
 * and MessengerFailureListener from a real database.
 *
 * @extends ServiceEntityRepository<WebhookEvent>
 */
class WebhookEventRepository extends ServiceEntityRepository
{
    private const EVENT_ID_CONSTRAINT = 'uniq_webhook_events_event_id';

    public function __construct(
        ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($registry, WebhookEvent::class);
    }

    /**
     * The database's unique index on event_id — not a prior SELECT — is the actual
     * arbiter here. Two concurrent requests with the same event_id both reach this
     * method; whichever flush wins persists the row, the other hits the unique
     * constraint violation and is treated as an idempotent duplicate, not an error.
     */
    public function insertIfNotExists(WebhookEvent $event): bool
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($event);

        try {
            $entityManager->flush();

            return true;
        } catch (UniqueConstraintViolationException $e) {
            $entityManager->detach($event);

            // Only event_id duplicates are a safe, expected idempotent no-op. Any other
            // unique constraint on this table (none exist today, but the table has grown
            // before) violating is a real integrity problem and must not be swallowed.
            if (!str_contains($e->getMessage(), self::EVENT_ID_CONSTRAINT)) {
                throw $e;
            }

            return false;
        }
    }

    public function findOneByEventId(string $eventId): ?WebhookEvent
    {
        return $this->findOneBy(['eventId' => $eventId]);
    }

    public function markNotified(WebhookEvent $event): void
    {
        $this->flushMutation($event, static fn (WebhookEvent $e) => $e->markNotified());
    }

    public function incrementRetry(WebhookEvent $event, string $error): void
    {
        $this->flushMutation($event, static fn (WebhookEvent $e) => $e->incrementRetry($error));
    }

    public function markDeadLetter(WebhookEvent $event, string $error): void
    {
        $this->flushMutation($event, static fn (WebhookEvent $e) => $e->markDeadLetter($error));
    }

    /**
     * Mutates and flushes without ever letting a flush failure here propagate. Doctrine's
     * UnitOfWork closes the EntityManager on ANY flush exception, not only the ones we
     * expect - and these three methods are called from the Messenger worker loop,
     * including its own failure-handling path (MessengerFailureListener). An uncaught
     * exception there would crash the single messenger:consume process (losing every
     * other queued message too), not just this one status update. Logged at "critical"
     * so the gap is visible without taking the worker down.
     *
     * @param callable(WebhookEvent): void $mutate
     */
    private function flushMutation(WebhookEvent $event, callable $mutate): void
    {
        $mutate($event);

        try {
            $this->getEntityManager()->flush();
        } catch (\Throwable $e) {
            $this->logger->critical('Failed to persist a webhook_events status update; the row may now be stale.', [
                'event_id' => $event->getEventId(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
