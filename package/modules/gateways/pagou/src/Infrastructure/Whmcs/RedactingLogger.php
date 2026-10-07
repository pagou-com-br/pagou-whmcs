<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Whmcs;

use Closure;

/**
 * Structured logging with a deny-list for credentials and cardholder data.
 * The adapter never accepts a raw PAN or CVV as a loggable value.
 */
final class RedactingLogger
{
    /** @var Closure(string,array<string,mixed>):void */
    private readonly Closure $writer;

    /** @param callable(string,array<string,mixed>):void $writer */
    public function __construct(callable $writer)
    {
        $this->writer = Closure::fromCallable($writer);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        ($this->writer)($message, $this->redact($context));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function redact(array $context): array
    {
        $redacted = [];
        foreach ($context as $key => $value) {
            $normalised = strtolower((string) $key);
            if (preg_match('/(?:authorization|api[_-]?key|secret|token|password|pan|card.?number|cvv|cvc|security.?code)/', $normalised)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }
            $redacted[$key] = is_array($value) ? $this->redact($value) : $this->redactString($value);
        }

        return $redacted;
    }

    private function redactString(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $value = preg_replace('/\b(?:\d[ -]?){13,19}\b/', '[REDACTED-CARD]', $value) ?? $value;
        $value = preg_replace('/\b\d{3,4}\b(?=\s*(?:cvv|cvc|security))/i', '[REDACTED]', $value) ?? $value;
        return $value;
    }
}
