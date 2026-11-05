# Roadmap

Implementation checklist for the Payment Webhook Ingestion & Notification Pipeline (see `SPEC.md` for requirements, `AGENTS.md` for locked architecture decisions). Each step gets a short analysis/checkpoint before moving to the next.

- [x] 1. `git init`, `.gitignore`, initial commit (`SPEC.md`, `AGENTS.md`, `CLAUDE.md`)
- [x] 2. Symfony 7.4.* skeleton (PHP 8.5) scaffolded and merged into the repo, `composer install` verified clean
- [x] 3. `composer require` runtime packages (Doctrine ORM/migrations, Messenger + AMQP transport, HTTP client, Monolog) and dev packages (MakerBundle, PHPUnit bridge, PHPStan + extensions, php-cs-fixer, Infection)
- [x] 4. `.env` / `.env.test` — `DATABASE_URL`, `MESSENGER_TRANSPORT_DSN`, `WEBHOOK_HMAC_SECRET`, `NOTIFICATION_MOCK_URL`, `MESSENGER_RETRY_*`
- [x] 5. `Enum/WebhookProcessingStatus`, `ValueObject/Money`, `Exception/InvalidWebhookPayloadException`, `Dto/PaymentWebhookPayload`
- [x] 6. `Entity/WebhookEvent` + `Repository/WebhookEventRepository` → migration (hand-edit `json`→`jsonb` via custom `JsonbType`) → migrated against dev Postgres, `doctrine:schema:validate` clean
- [x] 7. `Security/WebhookSignatureVerifier` (HMAC-SHA256, `hash_equals`)
- [x] 8. `Controller/Api/PaymentWebhookController` + `EventListener/ApiExceptionListener`
- [x] 9. `Message/SendPaymentNotification`, `Notification/{NotificationChannelInterface,NotificationPayload,MockHttpNotificationChannel}`, `MessageHandler/SendPaymentNotificationHandler`, `EventListener/MessengerFailureListener`
- [x] 10. `config/packages/messenger.yaml`, `config/packages/monolog.yaml`
- [x] 11. `mock/notification-service/` (plain-PHP mock + Dockerfile)
- [x] 12. `docker/php/Dockerfile`, `docker/nginx/default.conf`, `docker-compose.yml`, `docker-compose.override.e2e.yml`
- [ ] 13. `phpstan.neon`, `tests/object-manager.php`, `.php-cs-fixer.dist.php` — green at level 8
- [ ] 14. Unit tests (`tests/Unit/`) — green
- [ ] 15. Functional tests (`tests/Functional/`), test DB + migration — green
- [ ] 16. Functional test: out-of-order delivery guard — `charge.successful` processed correctly per-`event_id` even when no prior payment-intent record exists yet
- [ ] 17. `docker compose up --build` + manual smoke test (idempotency curl ×2, `FAIL`/`RETRY_ONCE`/`TIMEOUT` markers, RabbitMQ UI, direct SQL checks)
- [ ] 18. E2E tests (`tests/E2E/`) against the live stack
- [ ] 19. `.github/workflows/ci.yml` (5 jobs: static-analysis, unit-tests, functional-tests, e2e-tests in parallel + `build` gate; `mutation-testing` on `workflow_dispatch`)
- [ ] 20. `infection.json5` (stretch goal, non-blocking)
- [ ] 21. Code audit pass against the checklist in the plan — actually fix what's found
- [ ] 22. `README.md`, `ARCHITECTURE.md` (`EMAIL_TO_CLIENT.md` drafted, final pass by the user before submission)
- [ ] 23. Commit, push `main` to the existing `https://github.com/michalper/strix.git` remote

Full detail per step: `/Users/michalper/.claude/plans/przygotowany-plik-spec-md-cozy-pond.md`.
