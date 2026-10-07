<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

/**
 * The only service hooks/controllers may use to request remote work.
 * It is intentionally local-only: enqueue never calls Pagou.
 */
final class OperationScheduler
{
    public function __construct(private readonly OperationOutbox $outbox, private readonly AsyncClock $clock)
    {
    }

    /** @param array<string, mixed> $payload */
    public function schedule(
        string $id,
        OperationType $type,
        string $deduplicationKey,
        JobPriority $priority,
        array $payload,
        ?\DateTimeImmutable $availableAt = null,
    ): bool {
        $now = $this->clock->now();

        return $this->outbox->enqueue(new OperationJob(
            $id,
            $type,
            $deduplicationKey,
            $priority,
            $payload,
            $now,
            $availableAt ?? $now,
        ));
    }

    /** @param array<string, mixed> $payload */
    public function issueBoleto(string $id, string $attemptId, int $revision, array $payload): bool
    {
        return $this->schedule($id, OperationType::IssueBoleto, 'boleto:issue:' . $attemptId . ':' . $revision, JobPriority::Issuance, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function fetchBoletoPdf(string $id, string $attemptId, int $revision, array $payload): bool
    {
        return $this->schedule($id, OperationType::FetchBoletoPdf, 'boleto:pdf:' . $attemptId . ':' . $revision, JobPriority::Delivery, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function deliverInvoiceEmail(string $id, string $invoiceId, string $deliveryRevision, array $payload): bool
    {
        return $this->schedule($id, OperationType::DeliverInvoiceEmail, 'invoice:delivery:' . $invoiceId . ':' . $deliveryRevision, JobPriority::Delivery, $payload);
    }
}
