<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Domain;

use Pagou\Whmcs\Payment\Split\PaymentSplitProjection;

final class BoletoAttempt
{
    public const QUEUED = 'queued';
    public const AWAITING_REGISTRATION = 'awaiting_registration';
    public const READY = 'ready';
    public const PAID = 'paid';
    public const CANCEL_REQUESTED = 'cancel_requested';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';
    public const SUPERSEDED = 'superseded';

    public function __construct(
        public readonly string $id,
        public readonly string $invoiceId,
        public readonly int $revision,
        public readonly int $amountCentavos,
        public readonly string $dueDate,
        public readonly string $idempotencyKey,
        public readonly string $status = self::QUEUED,
        public readonly ?string $remoteId = null,
        public readonly ?BoletoArtifacts $artifacts = null,
        public readonly ?string $supersedesAttemptId = null,
        public readonly ?string $supersededByAttemptId = null,
        public readonly ?string $failureReason = null,
        public readonly ?PaymentSplitProjection $split = null,
    ) {
        if ($id === '' || $invoiceId === '' || $idempotencyKey === '' || $revision < 1 || $amountCentavos <= 0) {
            throw new \InvalidArgumentException('Invalid boleto attempt.');
        }
    }

    public function issued(BoletoCharge $charge): self
    {
        $artifacts = $charge->artifacts;
        // Provider responses do not contain the local PDF cache reference.
        if ($charge->remoteId === $this->remoteId && $this->artifacts?->localPdfKey !== null) {
            $artifacts = $artifacts->withLocalPdf($this->artifacts->localPdfKey);
        }
        return new self(
            $this->id,
            $this->invoiceId,
            $this->revision,
            $this->amountCentavos,
            $this->dueDate,
            $this->idempotencyKey,
            $this->projectStatus($charge),
            $charge->remoteId,
            $artifacts,
            $this->supersedesAttemptId,
            $this->supersededByAttemptId,
            $this->failureReason,
            $charge->split ?? $this->split,
        );
    }

    public function withArtifacts(BoletoArtifacts $artifacts): self
    {
        $status = in_array($this->status, [self::PAID, self::CANCELLED, self::FAILED, self::SUPERSEDED], true)
            ? $this->status : ($artifacts->isReady() ? self::READY : self::AWAITING_REGISTRATION);
        return new self(
            $this->id,
            $this->invoiceId,
            $this->revision,
            $this->amountCentavos,
            $this->dueDate,
            $this->idempotencyKey,
            $status,
            $this->remoteId,
            $artifacts,
            $this->supersedesAttemptId,
            $this->supersededByAttemptId,
            $this->failureReason,
            $this->split,
        );
    }

    private function projectStatus(BoletoCharge $charge): string
    {
        if (in_array($charge->status, ['paid', 'paid_with_discount', 'paid_with_fine_or_interest'], true)) {
            return self::PAID;
        }
        // A late registration notification cannot reopen a terminal local charge.
        if (in_array($this->status, [self::PAID, self::CANCELLED, self::SUPERSEDED], true)) {
            return $this->status;
        }
        return match ($charge->status) {
            'cancelled', 'canceled', 'removed', 'refunded' => self::CANCELLED,
            'failed', 'rejected' => self::FAILED,
            default => $charge->awaitsRegistration() ? self::AWAITING_REGISTRATION : self::READY,
        };
    }

    public function cancellationRequested(): self
    {
        return new self(
            $this->id,
            $this->invoiceId,
            $this->revision,
            $this->amountCentavos,
            $this->dueDate,
            $this->idempotencyKey,
            self::CANCEL_REQUESTED,
            $this->remoteId,
            $this->artifacts,
            $this->supersedesAttemptId,
            $this->supersededByAttemptId,
            $this->failureReason,
            $this->split,
        );
    }

    public function cancelled(): self
    {
        return new self(
            $this->id,
            $this->invoiceId,
            $this->revision,
            $this->amountCentavos,
            $this->dueDate,
            $this->idempotencyKey,
            self::CANCELLED,
            $this->remoteId,
            $this->artifacts,
            $this->supersedesAttemptId,
            $this->supersededByAttemptId,
            $this->failureReason,
            $this->split,
        );
    }

    public function supersededBy(string $replacementId): self
    {
        return new self(
            $this->id,
            $this->invoiceId,
            $this->revision,
            $this->amountCentavos,
            $this->dueDate,
            $this->idempotencyKey,
            self::SUPERSEDED,
            $this->remoteId,
            $this->artifacts,
            $this->supersedesAttemptId,
            $replacementId,
            $this->failureReason,
            $this->split,
        );
    }
}
