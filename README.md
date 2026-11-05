# Strix — Payment Webhook Ingestion & Notification Pipeline

A Symfony 7.4 (PHP 8.5) service that ingests payment-provider webhooks (`POST /api/v1/webhooks/payment`), persists each event idempotently, and dispatches an asynchronous notification to an external (mocked) sales-notification API via RabbitMQ/Symfony Messenger. This is a recruitment-task deliverable for Strix — see `SPEC.md` for the original brief.

For the resilience design (race conditions, out-of-order delivery, external API failures/backoff, malformed payloads) see [`ARCHITECTURE.md`](ARCHITECTURE.md). For the scope-negotiation email sent back to the client about the unrealistic parts of the brief (5 notification channels, AI reports, etc.) see [`EMAIL_TO_CLIENT.md`](EMAIL_TO_CLIENT.md).

## Prerequisites

- Docker
- Docker Compose v2 (the `docker compose` CLI, i.e. the plugin built into Docker Desktop/Engine — not the standalone legacy `docker-compose` binary)

No local PHP, Composer, or Postgres/RabbitMQ installation is required to run the stack.

## Quick start

```bash
docker compose up --build
```

This brings up the whole stack in one command:

- `postgres` — Postgres 16, with a healthcheck
- `rabbitmq` — RabbitMQ 3 with the management plugin
- `migrations` — a one-shot container that runs `doctrine:migrations:migrate --no-interaction` and exits
- `app` — PHP-FPM (built `--no-dev`, production dependencies only)
- `nginx` — serves the app on **`http://localhost:8080`**, proxying to `app` over FastCGI
- `worker` — runs `messenger:consume async` as a long-lived process
- `notification-mock` — the mocked external notification API

No manual migration step is needed: `migrations` runs automatically on startup, and both `app` and `worker` declare `depends_on: migrations: condition: service_completed_successfully`, so they only start once the schema is in place. `app`/`worker`/`migrations` also wait on `rabbitmq`/`postgres` healthchecks as appropriate.

## Sending a webhook

The example payload below is copied verbatim from `SPEC.md`. Requests must carry a `X-Signature` header: the hex-encoded HMAC-SHA256 of the raw request body, keyed with `WEBHOOK_HMAC_SECRET` (see `src/Security/WebhookSignatureVerifier.php`).

The secret shipped in `.env` is `ChangeMeInEnvLocal` — **this is a demo placeholder committed to the repo, not a real secret and not suitable for any real deployment.** A real deployment must override it in `.env.local` (already in `.gitignore`, never committed) or via a real environment variable.

```bash
PAYLOAD='{
  "event": "charge.successful",
  "event_id": "evt_9f8d7c6b5a4321",
  "timestamp": 1718029200,
  "livemode": true,
  "data": {
    "transaction": {
      "id": "txn_8a7b6c5d4e3f",
      "provider_reference": "PAYU-9876543210",
      "amount": {
        "value": 29990,
        "currency": "PLN"
      },
      "payment_method": {
        "type": "blik",
        "issuer": "mBank"
      },
      "status": "COMPLETED"
    },
    "customer": {
      "id": "cus_11223344",
      "email": "jan.kowalski@example.com",
      "billing_address": {
        "first_name": "Jan",
        "last_name": "Kowalski",
        "company": "Kowalski Dev Ltd",
        "country": "PL"
      }
    },
    "metadata": {
      "order_id": "ORD-2024-8839",
      "store_id": "store_main_pl",
      "channel": "mobile_app"
    }
  }
}'

SIGNATURE=$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "ChangeMeInEnvLocal" | sed 's/^.* //')

curl -i -X POST http://localhost:8080/api/v1/webhooks/payment \
  -H "Content-Type: application/json" \
  -H "X-Signature: $SIGNATURE" \
  -d "$PAYLOAD"
```

Note the `printf '%s'` rather than `echo`: it avoids a trailing newline being included in the signed bytes, which would make the signature not match what the server computes over `$request->getContent()`.

Expected response on the first POST — HTTP `202 Accepted`:

```json
{"status":"accepted","event_id":"evt_9f8d7c6b5a4321"}
```

### Idempotency: replaying the same request

Run the exact same `curl` command again (same `PAYLOAD`, same `SIGNATURE`):

```bash
curl -i -X POST http://localhost:8080/api/v1/webhooks/payment \
  -H "Content-Type: application/json" \
  -H "X-Signature: $SIGNATURE" \
  -d "$PAYLOAD"
```

Expected response — HTTP `200 OK` (no second notification is dispatched):

```json
{"status":"already_processed","event_id":"evt_9f8d7c6b5a4321"}
```

Uniqueness is enforced at the database level (`event_id` unique constraint), not with a check-then-insert read, so this is race-safe under concurrent duplicate delivery — see `ARCHITECTURE.md` section 1.

## Watching the async delivery

**RabbitMQ management UI:** `http://localhost:15672`. The `guest`/`guest` default does *not* work here — `guest` can only authenticate from `localhost` inside its own container. The real credentials, set via `RABBITMQ_DEFAULT_USER`/`RABBITMQ_DEFAULT_PASS` in `docker-compose.yml`, are:

```
user: strix
pass: strix
```

Use it to watch the `messages` queue drain as `worker` consumes it.

**What the worker actually sent to the mock service:** the mock exposes its own introspection endpoint over its internal NDJSON log. Query it from inside the compose network (adjust `order_id` to whatever you posted — here, the value from the example payload above):

```bash
docker compose exec notification-mock wget -qO- 'http://localhost:8080/__requests?order_id=ORD-2024-8839'
```

This returns the recorded request(s) the worker made to `/notify`, including the body it sent and the response status the mock returned.

## Forcing failure scenarios deterministically

The mock (`mock/notification-service/index.php`) branches on a substring of `order_id` in the incoming notification body:

- contains `FAIL` → always responds `500`
- contains `TIMEOUT` → sleeps 10s, then responds `504`
- contains `RETRY_ONCE` → fails (`500`) the first attempt for a given `event_id`, then succeeds on the next attempt

Example: post a webhook whose `order_id` forces a permanent failure (change `event_id` too, since it's the one already used above):

```bash
PAYLOAD='{"event":"charge.successful","event_id":"evt_fail_demo_001","timestamp":1718029200,"livemode":true,"data":{"transaction":{"id":"txn_8a7b6c5d4e3f","provider_reference":"PAYU-9876543210","amount":{"value":29990,"currency":"PLN"},"payment_method":{"type":"blik","issuer":"mBank"},"status":"COMPLETED"},"customer":{"id":"cus_11223344","email":"jan.kowalski@example.com","billing_address":{"first_name":"Jan","last_name":"Kowalski","company":"Kowalski Dev Ltd","country":"PL"}},"metadata":{"order_id":"ORD-2024-FAIL","store_id":"store_main_pl","channel":"mobile_app"}}}'

SIGNATURE=$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "ChangeMeInEnvLocal" | sed 's/^.* //')

curl -i -X POST http://localhost:8080/api/v1/webhooks/payment \
  -H "Content-Type: application/json" \
  -H "X-Signature: $SIGNATURE" \
  -d "$PAYLOAD"
```

The worker will retry this with exponential backoff (`MESSENGER_RETRY_*` env vars) and, once the retry policy is exhausted, route it to the Doctrine-backed `failed` transport (the dead-letter store). Check the result either via Messenger's own CLI:

```bash
docker compose exec app php bin/console messenger:failed:show
```

or directly in Postgres, which the worker also updates on every attempt regardless of queue state:

```bash
docker compose exec postgres psql -U strix -d strix -c "SELECT event_id, processing_status, retry_count, last_error FROM webhook_events;"
```

## Running the tests

Test suites are defined in `phpunit.dist.xml` as three named suites: `unit`, `functional`, `e2e`.

### Unit tests (no DB/broker needed)

The lean `app`/`worker` images are built `--no-dev` and have no PHPUnit. Build the `dev` target of `docker/php/Dockerfile` instead and run PHPUnit against the repo bind-mounted in:

```bash
docker build -f docker/php/Dockerfile --target dev -t strix-php-dev .

docker run --rm -v "$PWD":/app -w /app strix-php-dev vendor/bin/phpunit --testsuite=unit
```

### Functional tests (needs a real Postgres `strix_test` database)

Functional tests run under `APP_ENV=test`, which loads `.env.test`. Its `DATABASE_URL` points at database `strix` on the `postgres` service, but `config/packages/doctrine.yaml` appends `dbname_suffix: '_test%env(default::TEST_TOKEN)%'` under `when@test` — with `TEST_TOKEN` unset, that suffix is just `_test`, so the ORM actually connects to **`strix_test`**, a separate database from the one the running app/worker use. `TEST_TOKEN` exists so ParaTest can give each parallel process its own DB (`_test1`, `_test2`, …); it's unset for a normal single-process run.

With the stack already up (`docker compose up -d`), create and migrate the test database, then run the suite from the dev image:

```bash
docker compose exec postgres psql -U strix -d strix -c "CREATE DATABASE strix_test;"

docker run --rm --network strix_default -v "$PWD":/app -w /app \
  -e APP_ENV=test -e DATABASE_URL="postgresql://strix:strix@postgres:5432/strix?serverVersion=16&charset=utf8" \
  strix-php-dev php bin/console doctrine:migrations:migrate --no-interaction

docker run --rm --network strix_default -v "$PWD":/app -w /app \
  -e APP_ENV=test -e DATABASE_URL="postgresql://strix:strix@postgres:5432/strix?serverVersion=16&charset=utf8" \
  strix-php-dev vendor/bin/phpunit --testsuite=functional
```

(Adjust the `--network` name if Compose generated a different project network name on your machine — check with `docker network ls`.)

### E2E tests (needs the full live stack)

E2E tests exercise the real flow end-to-end: webhook POST → RabbitMQ → worker → mock notification service. They run from a dedicated `e2e-runner` service (the `dev` build target, with PHPUnit) defined in `docker-compose.override.e2e.yml`, layered on top of the normal stack — not from the lean `app` service.

```bash
docker compose -f docker-compose.yml -f docker-compose.override.e2e.yml up -d --build

docker compose -f docker-compose.yml -f docker-compose.override.e2e.yml exec -T e2e-runner vendor/bin/phpunit --testsuite=e2e
```

The e2e override also shortens the worker's retry schedule (2 fast, constant-delay retries) so DLQ-path tests don't have to wait through the full production backoff schedule.

### Static analysis

Same `dev` image as above, bind-mounted:

```bash
docker run --rm -v "$PWD":/app -w /app strix-php-dev vendor/bin/phpstan analyse        # level 8, config in phpstan.neon
docker run --rm -v "$PWD":/app -w /app strix-php-dev vendor/bin/php-cs-fixer fix --dry-run --diff
docker run --rm -v "$PWD":/app -w /app strix-php-dev vendor/bin/rector process --dry-run
```

Rector is dry-run-only by policy (see `AGENTS.md`): it flags candidate rewrites, CI fails on a non-empty diff, but rewrites are reviewed and applied by hand — never run it without `--dry-run`.

## Continuous integration

`.github/workflows/ci.yml` runs static analysis, unit, functional, and e2e tests as independent parallel jobs on every push/PR, plus an optional manually-triggered mutation-testing job (Infection) via `workflow_dispatch`.

## Known / deliberate limitations

- **PHPStan level 8, not 9** — `phpstan.neon` is pinned at level 8 as the practical ceiling for the time budget on this task, not level 9.
- **Dead-letter storage is a Doctrine `failed` transport, not a native RabbitMQ DLX/DLQ** — failed messages land in a Doctrine-backed table (inspectable via SQL or `messenger:failed:show`), not a second RabbitMQ queue, so no second broker topology is needed.
- **One mocked notification channel, not five** — the client's request for five notification channels and AI-generated reports is treated as a scope/expectations issue, addressed in `EMAIL_TO_CLIENT.md`, not implemented in code. A `NotificationChannelInterface` strategy exists so adding channels later is cheap, but only one (HTTP, mocked) is wired up.
- **Mutation testing (Infection) is an optional stretch goal**, not part of the required test gate — it only runs on manual `workflow_dispatch`, not on every push/PR.
