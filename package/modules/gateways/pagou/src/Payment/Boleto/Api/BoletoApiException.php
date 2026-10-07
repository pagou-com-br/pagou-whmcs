<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Api;

final class BoletoApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $kind = '',
        ?\Throwable $previous = null,
        public readonly ?\DateTimeImmutable $retryAt = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function outcomeUnknown(): bool
    {
        return $this->status === 0
            || $this->status === 408
            || $this->status === 429
            || $this->status >= 500
            || in_array($this->kind, ['transport', 'invalid_response', 'response_too_large'], true);
    }
}
