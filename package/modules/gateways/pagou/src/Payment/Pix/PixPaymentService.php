<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix;

use Pagou\Whmcs\Payment\Pix\Contracts\OperationJournal;
use Pagou\Whmcs\Payment\Pix\Contracts\ReconciliationScheduler;
use Pagou\Whmcs\Payment\Pix\Dto\CreateDuePixRequest;
use Pagou\Whmcs\Payment\Pix\Dto\CreatePixRequest;
use Pagou\Whmcs\Payment\Pix\Dto\PixCharge;
use Pagou\Whmcs\Payment\Pix\Dto\RefundPixRequest;
use Pagou\Whmcs\Payment\Pix\Exception\PixApiException;
use Pagou\Whmcs\Payment\Pix\Exception\PixUncertainOperation;
use Pagou\Whmcs\Payment\Pix\Mapper\PixPayloadMapper;
use Pagou\Whmcs\Payment\Pix\Mapper\PixResponseMapper;

/**
 * Financial mutations are journaled before the transport call. A timeout is never
 * retried here because the provider may already have accepted the same request.
 */
final class PixPaymentService
{
    public function __construct(
        private readonly PagouPixClient $client,
        private readonly PixPayloadMapper $payloads,
        private readonly PixResponseMapper $responses,
        private readonly OperationJournal $journal,
        private readonly ReconciliationScheduler $reconciliation,
    ) {
    }

    public function create(CreatePixRequest $request): PixCharge
    {
        return $this->mutate('pix.create', $request->idempotencyKey, ['attempt_id' => $request->attemptId, 'invoice_id' => $request->invoiceId, 'fingerprint' => hash('sha256', json_encode($this->payloads->immediate($request), JSON_THROW_ON_ERROR))], fn () =>
            $this->responses->charge($this->client->create($this->payloads->immediate($request), $request->idempotencyKey)->json),);
    }

    public function createDue(CreateDuePixRequest $request): PixCharge
    {
        return $this->mutate('pix.create_due', $request->idempotencyKey, [
            'attempt_id' => $request->attemptId,
            'invoice_id' => $request->invoiceId,
            'due_date' => $request->dueDate->value,
            'fingerprint' => hash('sha256', json_encode($this->payloads->due($request), JSON_THROW_ON_ERROR)),
        ], fn () => $this->responses->charge($this->client->createDue($this->payloads->due($request), $request->idempotencyKey)->json));
    }

    public function get(string $pixId): PixCharge
    {
        return $this->responses->charge($this->client->get($pixId)->json);
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->client->list($query);
    }

    public function cancel(string $attemptId, string $pixId, string $idempotencyKey): void
    {
        $this->mutateNoContent('pix.cancel', $idempotencyKey, ['attempt_id' => $attemptId, 'pix_id' => $pixId], fn () => $this->client->cancel($pixId, $idempotencyKey));
    }

    public function refund(RefundPixRequest $request): void
    {
        $this->mutateNoContent('pix.refund', $request->idempotencyKey, ['attempt_id' => $request->attemptId, 'pix_id' => $request->pixId,
            'fingerprint' => hash('sha256', json_encode($this->payloads->refund($request), JSON_THROW_ON_ERROR))], fn () =>
            $this->client->refund($request->pixId, $this->payloads->refund($request), $request->idempotencyKey));
    }

    /** @param array<string, mixed> $context */
    private function mutate(string $operation, string $idempotencyKey, array $context, callable $call): PixCharge
    {
        $prior = $this->claim($operation, $idempotencyKey, $context);
        if ($prior !== null) {
            $remoteId = $prior['remote_id'] ?? '';
            if (!is_string($remoteId) || $remoteId === '') {
                throw new PixUncertainOperation('A operação concluída precisa de conferência do identificador remoto.');
            }
            return $this->get($remoteId);
        }
        try {
            $charge = $call();
            $this->journal->succeeded($operation, $idempotencyKey, [
                'remote_id' => $charge->id,
                'status' => $charge->status,
            ]);
            return $charge;
        } catch (PixApiException $exception) {
            if (!$this->isUncertainStatus($exception->status)) {
                $this->journal->rejected($operation, $idempotencyKey, $exception->status);
                throw $exception;
            }
            return $this->markUncertain($operation, $idempotencyKey, $context, $exception);
        } catch (\Throwable $exception) {
            return $this->markUncertain($operation, $idempotencyKey, $context, $exception);
        }
    }

    /** @param array<string, mixed> $context */
    private function mutateNoContent(string $operation, string $idempotencyKey, array $context, callable $call): void
    {
        if ($this->claim($operation, $idempotencyKey, $context) !== null) {
            return;
        }
        try {
            $call();
            $this->journal->succeeded($operation, $idempotencyKey, []);
        } catch (PixApiException $exception) {
            if (!$this->isUncertainStatus($exception->status)) {
                $this->journal->rejected($operation, $idempotencyKey, $exception->status);
                throw $exception;
            }
            $this->markUncertain($operation, $idempotencyKey, $context, $exception);
        } catch (\Throwable $exception) {
            $this->markUncertain($operation, $idempotencyKey, $context, $exception);
        }
    }

    /** @param array<string, mixed> $context */
    private function markUncertain(string $operation, string $idempotencyKey, array $context, \Throwable $previous): never
    {
        $context['reason'] = $previous::class;
        try {
            $this->journal->uncertain($operation, $idempotencyKey, $context);
        } catch (\Throwable) {
            // The started reservation still prevents another financial dispatch.
        }
        $this->scheduleRecovery($operation, $idempotencyKey, $context);
        throw new PixUncertainOperation('O resultado da operação Pix é incerto e requer conferência.', 0, $previous);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function claim(string $operation, string $key, array $context): ?array
    {
        $claim = $this->journal->started($operation, $key, $context);
        if ($claim['status'] === 'claimed') {
            return null;
        }
        if ($claim['status'] === 'succeeded') {
            return $claim['result'];
        }
        if ($claim['status'] === 'failed') {
            throw new PixApiException('A solicitação anterior foi recusada. Revise a operação antes de iniciar outra.', (int) ($claim['result']['http_status'] ?? 400));
        }
        $this->scheduleRecovery($operation, $key, $context);
        throw new PixUncertainOperation('A solicitação anterior está em conferência. Não repita a operação financeira.');
    }

    /** @param array<string,mixed> $context */
    private function scheduleRecovery(string $operation, string $key, array $context): void
    {
        try {
            $this->reconciliation->schedule($operation, $key, $context);
        } catch (\Throwable) {
            // Persistence failure must not turn an unknown remote outcome into a safe-to-repeat failure.
        }
    }

    private function isUncertainStatus(int $status): bool
    {
        return $status === 0 || $status === 408 || $status === 429 || $status >= 500;
    }
}
