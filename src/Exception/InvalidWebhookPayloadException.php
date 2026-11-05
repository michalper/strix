<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidWebhookPayloadException extends \InvalidArgumentException
{
    /**
     * @param list<string> $fieldErrors
     */
    private function __construct(
        private readonly string $errorCode,
        private readonly array $fieldErrors,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function malformedJson(string $detail): self
    {
        return new self('malformed_json', [], $detail);
    }

    /**
     * @param list<string> $fieldErrors
     */
    public static function invalidFields(array $fieldErrors): self
    {
        return new self('invalid_payload', $fieldErrors, 'Webhook payload failed validation.');
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return list<string>
     */
    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
