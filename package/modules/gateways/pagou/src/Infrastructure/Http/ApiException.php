<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Http;

final class ApiException extends \RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        public readonly string $kind,
        public readonly int $statusCode = 0,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function isConclusiveRejection(): bool
    {
        if (in_array($this->kind, ['transport', 'invalid_response', 'response_too_large'], true)) {
            return false;
        }
        if ($this->statusCode === 409) {
            // A conflict without a documented business code may still be in flight.
            $code = $this->context['code'] ?? '';
            return is_string($code) && in_array($code, [
                'charge_not_authorized', 'charge_not_reversible', 'charge_not_cancellable',
                'charge_not_failed', 'charge_not_editable',
            ], true);
        }
        return $this->statusCode >= 400 && $this->statusCode < 500
            && !in_array($this->statusCode, [408, 425, 429], true);
    }
}
