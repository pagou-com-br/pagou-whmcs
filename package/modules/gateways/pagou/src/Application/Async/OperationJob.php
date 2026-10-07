<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

/**
 * An operation is durable intent. Payloads must contain references and no secrets.
 */
final class OperationJob
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $id,
        public readonly OperationType $type,
        public readonly string $deduplicationKey,
        public readonly JobPriority $priority,
        public readonly array $payload,
        public readonly \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $availableAt,
        public JobStatus $status = JobStatus::Queued,
        public int $attempts = 0,
        public ?string $leaseToken = null,
        public ?\DateTimeImmutable $leaseExpiresAt = null,
        public ?string $lastError = null,
        public ?string $uncertainReason = null,
    ) {
    }

    public function isAvailableAt(\DateTimeImmutable $now): bool
    {
        return !$this->status->isTerminal()
            && $this->status !== JobStatus::Uncertain
            && $this->availableAt <= $now;
    }
}
