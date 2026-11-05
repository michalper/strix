<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Safety net for /api/* requests: anything that reaches here is an exception the
 * controller itself did not already turn into a JSON error response (expected
 * validation/auth failures are handled directly in PaymentWebhookController).
 * Guarantees the client always gets JSON, never Symfony's HTML debug page, and
 * that the full exception is logged before responding.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class ApiExceptionListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();

        $statusCode = $throwable instanceof HttpExceptionInterface
            ? $throwable->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        $this->logger->error('Unhandled exception on API request.', [
            'path' => $request->getPathInfo(),
            'exception' => $throwable::class,
            'message' => $throwable->getMessage(),
            'status_code' => $statusCode,
            'trace' => $throwable->getTraceAsString(),
        ]);

        $event->setResponse(new JsonResponse(
            ['error' => $statusCode >= 500 ? 'internal_error' : 'request_error'],
            $statusCode,
        ));
    }
}
