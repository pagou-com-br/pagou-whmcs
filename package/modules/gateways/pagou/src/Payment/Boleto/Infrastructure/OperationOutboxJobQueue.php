<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Infrastructure;

use Pagou\Whmcs\Application\Async\JobPriority;
use Pagou\Whmcs\Application\Async\OperationScheduler;
use Pagou\Whmcs\Application\Async\OperationType;
use Pagou\Whmcs\Payment\Boleto\Contracts\JobQueue;

/** Bridges boleto handlers to the durable module operation outbox. */
final class OperationOutboxJobQueue implements JobQueue
{
    public function __construct(private readonly OperationScheduler $scheduler)
    {
    }

    /** @param array<string, scalar|null> $payload */
    public function enqueue(string $name, array $payload, string $deduplicationKey): void
    {
        [$type, $priority] = match ($name) {
            'boleto.emit' => [OperationType::IssueBoleto, JobPriority::Issuance],
            'boleto.cache_pdf' => [OperationType::FetchBoletoPdf, JobPriority::Delivery],
            'boleto.refresh' => [OperationType::ReconcilePayment, JobPriority::PaymentConfirmation],
            'boleto.replacement.refresh' => [OperationType::ReplaceBoleto, JobPriority::FinancialRecovery],
            default => throw new \InvalidArgumentException('Unknown boleto queue operation.'),
        };

        $this->scheduler->schedule($this->uuid(), $type, $deduplicationKey, $priority, $payload);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
