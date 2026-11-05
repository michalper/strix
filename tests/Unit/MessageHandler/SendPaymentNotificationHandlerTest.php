<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\SendPaymentNotification;
use App\MessageHandler\SendPaymentNotificationHandler;
use App\Notification\MockHttpNotificationChannel;
use App\Repository\WebhookEventRepository;
use App\Tests\Support\WebhookEventTestFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * @internal
 *
 * @coversNothing
 */
final class SendPaymentNotificationHandlerTest extends TestCase
{
    public function testSuccessfulDeliveryMarksNotifiedAndNeverRetries(): void
    {
        $event = WebhookEventTestFactory::create();
        $repository = $this->createMock(WebhookEventRepository::class);
        $repository->method('findOneByEventId')->willReturn($event);
        $repository->expects(self::once())->method('markNotified')->with($event);
        $repository->expects(self::never())->method('incrementRetry');

        $handler = $this->makeHandler($repository, new MockResponse('{}', ['http_code' => 200]));

        $handler(new SendPaymentNotification($event->getEventId()));
    }

    public function testClientErrorIsUnrecoverableAndNeverMarksNotified(): void
    {
        $event = WebhookEventTestFactory::create();
        $repository = $this->createMock(WebhookEventRepository::class);
        $repository->method('findOneByEventId')->willReturn($event);
        $repository->expects(self::once())->method('incrementRetry')->with($event, self::callback(static fn ($value): bool => \is_string($value)));
        $repository->expects(self::never())->method('markNotified');

        $handler = $this->makeHandler($repository, new MockResponse('{}', ['http_code' => 400]));

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $handler(new SendPaymentNotification($event->getEventId()));
    }

    /**
     * @param class-string<\Throwable> $expectedExceptionInterface
     */
    #[DataProvider('provideTransientFailureIsRethrownForMessengerToRetryCases')]
    public function testTransientFailureIsRethrownForMessengerToRetry(MockResponse $response, string $expectedExceptionInterface): void
    {
        $event = WebhookEventTestFactory::create();
        $repository = $this->createMock(WebhookEventRepository::class);
        $repository->method('findOneByEventId')->willReturn($event);
        $repository->expects(self::once())->method('incrementRetry')->with($event, self::callback(static fn ($value): bool => \is_string($value)));
        $repository->expects(self::never())->method('markNotified');

        $handler = $this->makeHandler($repository, $response);

        $this->expectException($expectedExceptionInterface);

        $handler(new SendPaymentNotification($event->getEventId()));
    }

    /**
     * @return iterable<string, array{0: MockResponse, 1: class-string<\Throwable>}>
     */
    public static function provideTransientFailureIsRethrownForMessengerToRetryCases(): iterable
    {
        yield '5xx server error' => [
            new MockResponse('{}', ['http_code' => 500]),
            ServerExceptionInterface::class,
        ];

        yield 'transport-level timeout' => [
            new MockResponse('', ['error' => 'Connection timed out']),
            TransportExceptionInterface::class,
        ];

        yield '429 too many requests' => [
            new MockResponse('{}', ['http_code' => 429]),
            ClientExceptionInterface::class,
        ];

        yield '408 request timeout' => [
            new MockResponse('{}', ['http_code' => 408]),
            ClientExceptionInterface::class,
        ];
    }

    public function testUnknownEventIdIsUnrecoverable(): void
    {
        $repository = $this->createMock(WebhookEventRepository::class);
        $repository->method('findOneByEventId')->willReturn(null);
        $repository->expects(self::never())->method('incrementRetry');
        $repository->expects(self::never())->method('markNotified');

        $handler = $this->makeHandler($repository, new MockResponse('{}', ['http_code' => 200]));

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $handler(new SendPaymentNotification('evt_does_not_exist'));
    }

    private function makeHandler(WebhookEventRepository $repository, MockResponse $response): SendPaymentNotificationHandler
    {
        $channel = new MockHttpNotificationChannel(new MockHttpClient($response), 'http://notification-mock.test/notify');

        return new SendPaymentNotificationHandler($repository, $channel, new NullLogger());
    }
}
