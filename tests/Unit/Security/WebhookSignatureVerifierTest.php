<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureVerifierTest extends TestCase
{
    public function testValidSignaturePasses(): void
    {
        $body = '{"foo":"bar"}';
        $verifier = new WebhookSignatureVerifier('secret');

        self::assertTrue($verifier->verify($body, hash_hmac('sha256', $body, 'secret')));
    }

    public function testSignatureComputedWithWrongSecretFails(): void
    {
        $body = '{"foo":"bar"}';
        $verifier = new WebhookSignatureVerifier('secret');

        self::assertFalse($verifier->verify($body, hash_hmac('sha256', $body, 'different-secret')));
    }

    public function testGarbageSignatureFails(): void
    {
        $verifier = new WebhookSignatureVerifier('secret');

        self::assertFalse($verifier->verify('{"foo":"bar"}', 'not-a-valid-signature'));
    }

    public function testMissingSignatureHeaderFails(): void
    {
        $verifier = new WebhookSignatureVerifier('secret');

        self::assertFalse($verifier->verify('{"foo":"bar"}', null));
    }

    public function testEmptySignatureHeaderFails(): void
    {
        $verifier = new WebhookSignatureVerifier('secret');

        self::assertFalse($verifier->verify('{"foo":"bar"}', ''));
    }

    public function testSignatureIsBoundToExactBodyBytes(): void
    {
        $verifier = new WebhookSignatureVerifier('secret');
        $signature = hash_hmac('sha256', '{"foo":"bar"}', 'secret');

        self::assertFalse($verifier->verify('{"foo":"baz"}', $signature));
    }
}
