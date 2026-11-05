# AGENTS.md

Conventions and architecture context for any AI coding agent working in this repository (Claude Code, Codex, Copilot, etc.).

## Project status

Greenfield: no application code exists yet. The repository currently contains only `SPEC.md` (the client brief). The implementation is being designed via a spec-driven development flow — update this file with real build/test/lint commands as soon as the Symfony skeleton and tooling exist.

## What this is

A recruitment task for Strix: a payment webhook ingestion & notification pipeline. Full requirements, the exact webhook JSON payload, and delivery options are in `SPEC.md` — read it before making architectural changes. Required deliverables: `EMAIL_TO_CLIENT.md`, `ARCHITECTURE.md`, a working PHP system, `docker-compose.yml`, and a public GitHub repo with a `README.md` containing setup + curl instructions.

## Locked architecture decisions

These were decided with the project owner during planning. Do not re-litigate them without asking:

- **Stack**: PHP 8.5, Symfony 7.4 LTS.
- **Database**: PostgreSQL. Idempotency on incoming webhooks is enforced via a unique constraint on `event_id` (insert-and-catch-violation), not a check-then-insert read/write pair — the latter is the race condition the spec explicitly calls out.
- **Queue**: RabbitMQ via Symfony Messenger's AMQP transport. A dedicated worker container runs `messenger:consume` as a long-lived process. Failed messages exhaust an exponential-backoff retry policy before routing to a `failure_transport` (dead-letter storage).
- **Endpoint auth**: HMAC-SHA256 signature header (Stripe-style), verified with `hash_equals` (constant-time comparison).
- **Notification channels**: exactly one mocked external API for now. The client's "5 channels" request is addressed in `EMAIL_TO_CLIENT.md` as a scope/expectations issue, not solved in code — though the channel is built behind an interface so adding more later is cheap.
- **Static analysis**: PHPStan at the max level. Code should satisfy it through real typing (Entities, Controller, MessageHandler, DTOs), not `@phpstan-ignore` suppressions. Rector runs as a complementary dry-run check (dead code, PHP 8.5/Symfony-version rulesets) — it flags, CI fails on diff, but rewrites are applied by hand after review, never auto-committed.
- **Coding standards**: php-cs-fixer with `@Symfony` + `@Symfony:risky` (declare_strict_types enforced, strict comparisons) plus import/class-element ordering — `--dry-run --diff` in CI, never auto-fix there; fixing is a local `vendor/bin/php-cs-fixer fix` before commit. `declare(strict_types=1)` on every file, constructor property promotion + `readonly` for values that never change post-construction, classes `final` by default. The one current exception, `WebhookEventRepository`, is documented inline (`src/Repository/WebhookEventRepository.php`) because unit tests need to mock it — any future `final` exception needs the same kind of inline justification, not a silent removal.
- **Design patterns**: used where they earn their place, not sprinkled on — e.g. Repository for webhook persistence, a `NotificationChannelInterface` strategy for the dispatcher, DTOs for incoming payload validation/mapping rather than raw arrays in domain logic. Audit and remove any abstraction that doesn't carry its weight (the spec explicitly asks to strip AI-slop/over-engineering).
- **Testing**: three layers — unit, functional (Symfony `WebTestCase`/`KernelTestCase`), and e2e (full `docker compose` flow: webhook POST → worker consumes from RabbitMQ → mock external API receives the call). Coverage must include edge cases and exceptions (malformed/incomplete payload, missing or invalid signature, duplicate `event_id` under concurrency, retry exhaustion landing in the DLQ) — not only the happy path. The unit layer targets 100% line coverage where a coverage driver is available in the environment — it's the cheap, DB-free layer, so gaps there should be closed before leaning on functional/e2e for the same ground. Mutation testing (Infection) is an optional stretch goal, not a requirement.
- **CI**: GitHub Actions with independent, parallel jobs (static analysis [PHPStan, php-cs-fixer, Rector — all dry-run/no-autofix], unit, functional, e2e) rather than one sequential job, so total wall-clock time tracks the slowest job (e2e) instead of the sum of all of them.
- **License**: MIT.
- **Delivery**: public GitHub repo `https://github.com/michalper/strix.git`.

## Failure modes the architecture must defend against

These come directly from `SPEC.md`'s "Architektura" section and drive the data model and endpoint/worker design:

- **Race condition**: two webhooks with the identical `event_id` arriving in the same millisecond must still result in exactly one persisted record.
- **Out-of-order delivery**: `charge.successful` can arrive before any earlier payment-intent state exists — processing must be correct per-`event_id` regardless of arrival order, not dependent on a prior record already existing.
- **External API failures**: timeouts/5xx from the notification target must trigger exponential backoff, not an immediate drop or an infinite retry loop, and must eventually land in a dead-letter store if exhausted.
- **Poison pills**: malformed or incomplete HTTP payloads must fail cleanly (4xx with a readable log entry) — they must never 500 the endpoint or crash the worker.
