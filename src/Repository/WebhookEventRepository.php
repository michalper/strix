<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WebhookEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebhookEvent>
 */
final class WebhookEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
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
        } catch (UniqueConstraintViolationException) {
            $entityManager->detach($event);

            return false;
        }
    }

    public function findOneByEventId(string $eventId): ?WebhookEvent
    {
        return $this->findOneBy(['eventId' => $eventId]);
    }

    public function markNotified(WebhookEvent $event): void
    {
        $event->markNotified();
        $this->getEntityManager()->flush();
    }

    public function incrementRetry(WebhookEvent $event, string $error): void
    {
        $event->incrementRetry($error);
        $this->getEntityManager()->flush();
    }

    public function markDeadLetter(WebhookEvent $event, string $error): void
    {
        $event->markDeadLetter($error);
        $this->getEntityManager()->flush();
    }
}
