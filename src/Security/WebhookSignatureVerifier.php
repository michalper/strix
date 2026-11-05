<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Verifies the Stripe-style HMAC-SHA256 signature header on incoming webhooks.
 * Computed over the raw request body bytes, before any JSON decoding, so the
 * signature covers exactly what the provider signed.
 */
final readonly class WebhookSignatureVerifier
{
    public function __construct(
        private string $secret,
    ) {}

    public function verify(string $rawBody, ?string $signatureHeader): bool
    {
        if (null === $signatureHeader || '' === $signatureHeader) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->secret);

        return hash_equals($expected, $signatureHeader);
    }
}
