<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\ApiExceptionListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @internal
 *
 * @coversNothing
 */
final class ApiExceptionListenerTest extends TestCase
{
    public function testNonApiRequestIsIgnored(): void
    {
        $event = $this->makeEvent('/not-api/path', new \RuntimeException('boom'));

        (new ApiExceptionListener(new NullLogger()))($event);

        self::assertFalse($event->hasResponse());
    }

    public function testUnexpectedExceptionOnApiRequestReturnsJson500WithoutLeakingDetails(): void
    {
        $event = $this->makeEvent('/api/v1/webhooks/payment', new \RuntimeException('something internal broke'));

        (new ApiExceptionListener(new NullLogger()))($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'internal_error'], json_decode((string) $response->getContent(), true));
    }

    public function testHttpExceptionOnApiRequestPreservesItsStatusCode(): void
    {
        $event = $this->makeEvent('/api/v1/webhooks/payment', new HttpException(400, 'nope'));

        (new ApiExceptionListener(new NullLogger()))($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'request_error'], json_decode((string) $response->getContent(), true));
    }

    private function makeEvent(string $path, \Throwable $throwable): ExceptionEvent
    {
        $kernel = self::createStub(HttpKernelInterface::class);

        return new ExceptionEvent($kernel, Request::create($path), HttpKernelInterface::MAIN_REQUEST, $throwable);
    }
}
