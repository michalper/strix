# Architecture

How this system defends against the four non-happy-path scenarios called out in `SPEC.md`'s "Architektura" section. Each one maps to a concrete implementation decision, not just a design intention — file references point at the actual code.

## 1. Race condition / concurrent requests

**Risk:** two webhooks with the identical `event_id` arrive in the same millisecond (retried delivery, provider-side duplication). A check-then-insert (`SELECT ... WHERE event_id = ?` followed by `INSERT`) has a race window between the two statements — both requests can pass the `SELECT` before either `INSERT` commits, producing two rows for one event.

**Decision:** the database is the arbiter, not application logic. `webhook_events.event_id` has a unique index (`src/Entity/WebhookEvent.php:24`). Both concurrent requests build an entity and attempt to persist it; only one `INSERT` can win the unique constraint, and the loser fails atomically at the database level — there is no read step to race against.

**Implementation:** `WebhookEventRepository::insertIfNotExists()` (`src/Repository/WebhookEventRepository.php:28-42`) does `persist()` + `flush()` and catches `UniqueConstraintViolationException`. `PaymentWebhookController` (`src/Controller/Api/PaymentWebhookController.php:60-66`) treats a caught violation as `already_processed` (HTTP 200), not an error — duplicates are idempotent no-ops, never a 500 or a second notification dispatch.

**Verified by:** roadmap step 15 (functional tests), concurrency case explicitly listed in `AGENTS.md`'s testing section; manual double-POST smoke test in roadmap step 17.

## 2. Out-of-order delivery

**Risk:** `charge.successful` arrives before any earlier "payment intent" state has been persisted, so a design that requires a prior intent record to exist (e.g. "update the row created when the intent started") would fail or silently no-op on legitimate first-arriving events.

**Decision:** the system doesn't model a separate "payment intent" entity to be out of order *with*. Each webhook is processed as a fully self-contained fact, keyed only by its own `event_id` — `WebhookEvent::fromPayload()` (`src/Entity/WebhookEvent.php:98-122`) builds the entire row from the incoming payload alone, with no lookup of or dependency on any other record. There is nothing for a given event to arrive "before," so arrival order cannot break processing correctness.

**Implementation:** `PaymentWebhookPayload::fromRawJson()` (`src/Dto/PaymentWebhookPayload.php:38-124`) parses the full event independently; `insertIfNotExists()` persists it independently. Processing for event A never reads or waits on state written by event B.

**Verified by:** roadmap step 16 — dedicated functional test asserting `charge.successful` is processed correctly with no prior record for that `event_id` in the database.

## 3. External API failures & backoff

**Risk:** the notification target returns 5xx or times out. Dropping the notification loses it silently; retrying forever risks an unbounded queue of stuck messages; retrying immediately in a tight loop hammers an already-struggling downstream service.

**Decision:** transient failures (5xx, timeout) get exponential backoff via Symfony Messenger's retry strategy; failures that are not transient (4xx — our request was malformed or rejected outright) are not retried at all; retries that exhaust the policy land in a dead-letter store instead of disappearing.

**Implementation:**
- `config/packages/messenger.yaml` — `retry_strategy` on the `async` transport (`max_retries`, `delay`, `multiplier`, `max_delay`, all env-configurable) and a Doctrine-backed `failed` transport as the DLQ (inspectable via SQL or `messenger:failed:show`, no second broker needed).
- `SendPaymentNotificationHandler::__invoke()` (`src/MessageHandler/SendPaymentNotificationHandler.php:48-67`) branches on exception type: `ClientExceptionInterface` (4xx) throws `UnrecoverableMessageHandlingException` — no retry; `ServerExceptionInterface`/`TransportExceptionInterface` (5xx/timeout) rethrows the original exception so Messenger's retry strategy takes over.
- `MessengerFailureListener` (`src/EventListener/MessengerFailureListener.php:24-51`) fires only when `$event->willRetry()` is false (retries exhausted, or unrecoverable) and marks the `WebhookEvent` as `WebhookProcessingStatus::DeadLetter` (`src/Enum/WebhookProcessingStatus.php:11`), recording the final error and retry count.
- Every attempt, successful or not, updates `retryCount`/`lastError` on the webhook row (`WebhookEvent::incrementRetry()`, `src/Entity/WebhookEvent.php:195-199`), so the DB reflects delivery state without needing to inspect the queue.

**Verified by:** roadmap step 17 manual smoke test against the mock service's `FAIL`/`RETRY_ONCE`/`TIMEOUT` markers; roadmap step 15/18 automated coverage of retry exhaustion → DLQ.

## 4. Poison pills / malformed payloads

**Risk:** a corrupted, incomplete, or outright malicious HTTP request must never crash the worker, 500 the endpoint, or vanish without a trace — it has to fail predictably and leave a readable log entry.

**Decision:** validation happens in layers, each with its own typed failure mode, before anything touches the database:
1. **Signature first.** `WebhookSignatureVerifier::verify()` (`src/Security/WebhookSignatureVerifier.php:19-28`) checks the HMAC-SHA256 signature over the raw body with `hash_equals` (constant-time) before any JSON parsing — an unsigned or forged request never reaches payload parsing at all. Missing/invalid signature → `401`, logged, no further processing.
2. **Structural + field validation.** `PaymentWebhookPayload::fromRawJson()` (`src/Dto/PaymentWebhookPayload.php:38-124`) catches `JsonException` for non-JSON bodies and accumulates per-field errors (missing/wrong-typed required fields, invalid amount, invalid currency code) instead of failing on the first one, so the caller sees everything wrong with the payload at once. Thrown as `InvalidWebhookPayloadException` with an error code and field list.
3. **Controller maps both to clean 4xx JSON.** `PaymentWebhookController::__invoke()` (`src/Controller/Api/PaymentWebhookController.php:37-57`) catches `InvalidWebhookPayloadException` and returns `400` with `{"error": ..., "fields": [...]}`, logging a `warning` (not `error` — this is an expected, handled case, not a bug) with the error code and field list.
4. **Safety net for everything else.** `ApiExceptionListener` (`src/EventListener/ApiExceptionListener.php:30-56`) catches any exception that reaches the kernel from an `/api/*` request, logs the full exception (class, message, status, stack trace) at `error`, and always returns JSON — Symfony's HTML debug page can never leak to a webhook caller, and an unexpected bug degrades to a clean 500 response instead of crashing the process.

**Verified by:** roadmap step 14 unit tests (DTO validation edge cases) and step 15 functional tests (missing signature, malformed JSON, missing/invalid fields) — all listed explicitly in `AGENTS.md`'s testing section.
