<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use Closure;
use PDO;
use Pagou\Whmcs\Domain\Money;
use Pagou\Whmcs\Infrastructure\Persistence\LeaseRepository;
use Pagou\Whmcs\Infrastructure\Whmcs\NativePixRefundPort;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\PaymentTransactionResolver;
use Pagou\Whmcs\Payment\Pix\Dto\{PixCharge, RefundPixRequest};
use Pagou\Whmcs\Payment\Pix\Exception\PixApiException;
use Pagou\Whmcs\Payment\Pix\Infrastructure\PdoPixRefundStore;
use Pagou\Whmcs\Support\Uuid;

/** Request once, then reconcile only the exact refund exposed by the public Pix API. */
final class PixRefundRuntime
{
    private readonly Closure $send;
    private readonly NativePixRefundPort $native;
    private readonly PdoPixRefundStore $store;

    /** @param callable(RefundPixRequest):void $send */
    public function __construct(private readonly PDO $pdo, callable $send)
    {
        $this->send = Closure::fromCallable($send);
        $this->native = new NativePixRefundPort($pdo);
        $this->store = new PdoPixRefundStore($pdo);
    }

    public function request(int $invoiceId, string $transactionId, int $amount, int $actorId): void
    {
        if ($amount < 1 || $actorId < 1) {
            throw new \InvalidArgumentException('Informe um valor de reembolso válido.');
        }
        $attempt = (new PaymentTransactionResolver($this->pdo))->resolve($invoiceId, $transactionId, 'pix');
        $receipt = $this->native->originalAmount($invoiceId, $transactionId, (int) $attempt['client_id']);
        if ($amount > $receipt) {
            throw new \DomainException('O reembolso não pode superar o valor recebido neste Pix.');
        }
        $existing = $this->store->find((string) $attempt['id']);
        if ($existing === null) {
            // Existing manual outflows need review, rather than another remote debit.
            $check = $this->pdo->prepare('SELECT 1 FROM tblaccounts WHERE invoiceid = ? AND amountout > 0 LIMIT 1');
            $check->execute([$invoiceId]);
            if ($check->fetchColumn() !== false) {
                throw new \DomainException('Esta fatura já possui uma devolução. Confira os registros antes de solicitar outra.');
            }
        }
        $row = $this->store->reserve($attempt, $transactionId, $amount, $receipt, $actorId);
        if (!$row['created']) {
            return;
        }
        try {
            ($this->send)(new RefundPixRequest(
                (string) $row['remote_id'],
                (string) $row['attempt_id'],
                hash('sha256', 'whmcs-pix-refund:' . $row['id']),
                1,
                new \Pagou\Whmcs\Payment\Pix\Value\Money($amount),
                'Reembolso solicitado na fatura WHMCS ' . $invoiceId
            ));
        } catch (PixApiException $error) {
            $uncertain = $error->status < 400 || $error->status >= 500 || in_array($error->status, [408, 429], true);
            $this->store->mark((string) $row['attempt_id'], $uncertain ? 'uncertain' : 'rejected', $uncertain ? 'remote_outcome_unknown' : 'request_rejected');
        } catch (\Throwable) {
            // Reservation and journal remain, even if the HTTP response was lost.
            $this->store->mark((string) $row['attempt_id'], 'uncertain', 'remote_outcome_unknown');
        }
    }

    /** Return success once, allowing WHMCS to book the refund and honor its native options.
     * @return array<string,mixed> */
    public function authorizeNative(string $attemptId): array
    {
        $row = $this->store->find($attemptId);
        if ($row !== null && $row['status'] === 'confirmed' && $this->store->claimNative($attemptId)) {
            $result = ['status' => 'success', 'transid' => $row['provider_refund_id'], 'rawdata' => ['result' => 'refund_confirmed']];
            // Pagou gives the fee back only on a total refund; WHMCS would otherwise reverse it on a partial one.
            if ((int) $row['amount_cents'] < (int) $row['receipt_cents']) {
                $result['fees'] = '0.00';
            }

            return $result;
        }
        $reason = match ($row['status'] ?? '') {
            'applied' => 'Este reembolso já foi registrado. Atualize a fatura.',
            'rejected' => 'A solicitação não foi aceita pela Pagou. Confira a situação com o suporte.',
            'review', 'applying' => 'O registro deste reembolso precisa de conferência. Não solicite outra devolução.',
            default => 'A devolução está em processamento na Pagou. Aguarde a confirmação e conclua pela aba Refund. O pedido existente será reutilizado.',
        };
        return ['status' => 'declined', 'declinereason' => $reason, 'rawdata' => ['result' => 'refund_pending_or_review']];
    }

    /** Record only an exact native outflow after WHMCS has processed its own refund.
     * @return list<array<string,mixed>> */
    public function confirmNative(int $invoiceId): array
    {
        $query = $this->pdo->prepare("SELECT * FROM pagou_pix_refunds WHERE invoice_id = ? AND provider_refund_id IS NOT NULL AND status IN ('confirmed','applying','review')");
        $query->execute([$invoiceId]);
        $applied = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $leases = new LeaseRepository($this->pdo);
            $owner = Uuid::v4();
            $key = 'pix-refund:' . $row['attempt_id'];
            if (!$leases->acquire($key, $owner, 120)) {
                continue;
            }
            try {
                if ($this->native->exists($row)) {
                    $this->store->applied($row);
                    $applied[] = $row;
                }
            } catch (\Throwable) {
                $this->store->mark((string) $row['attempt_id'], 'review', 'native_refund_mismatch');
            } finally {
                $leases->release($key, $owner);
            }
        }
        return $applied;
    }

    /** Returns null only for a charge without a refund initiated in this module. */
    public function reconcile(string $attemptId, PixCharge $charge): ?string
    {
        $row = $this->store->find($attemptId);
        if ($row === null) {
            return null;
        }
        if ($row['status'] === 'applied') {
            return 'applied';
        }
        $leases = new LeaseRepository($this->pdo);
        $owner = Uuid::v4();
        if (!$leases->acquire('pix-refund:' . $attemptId, $owner, 120)) {
            return 'pending';
        }
        try {
            return $this->apply($attemptId, $charge);
        } finally {
            $leases->release('pix-refund:' . $attemptId, $owner);
        }
    }

    private function apply(string $attemptId, PixCharge $charge): string
    {
        $row = $this->store->find($attemptId) ?? throw new \LogicException('Refund not found.');
        if ($row['status'] === 'applied') {
            return 'applied';
        }
        try {
            if ($charge->id !== $row['remote_id'] || $charge->amountCents !== (int) $row['receipt_cents']) {
                throw new \DomainException('refund_charge_mismatch');
            }
            if ($this->native->originalAmount((int) $row['invoice_id'], (string) $row['original_transaction_id'], (int) $row['client_id']) !== (int) $row['receipt_cents']) {
                throw new \DomainException('refund_original_receipt_changed');
            }
            $refund = $charge->raw['refund'] ?? null;
            if (!is_array($refund)) {
                return $row['status'] === 'rejected' ? 'rejected' : 'pending';
            }
            $amount = $refund['amount'] ?? null;
            $providerId = $refund['external_id'] ?? null;
            if (!is_scalar($amount) || Money::fromDecimal((string) $amount)->centavos() !== (int) $row['amount_cents']) {
                throw new \DomainException('refund_amount_mismatch');
            }
            // Status 3 is the completed refund, status 2 is only an accepted request.
            if ((string) ($refund['status'] ?? '') !== '3') {
                return 'pending';
            }
            if (
                $charge->status !== 'refunded' || !is_string($providerId) || trim($providerId) === ''
                || strlen($providerId) > 128 || $providerId === $row['original_transaction_id']
                || (!empty($row['provider_refund_id']) && $providerId !== $row['provider_refund_id'])
            ) {
                throw new \DomainException('refund_identity_mismatch');
            }
            $this->store->confirm($attemptId, $providerId);
            $row = $this->store->find($attemptId) ?? throw new \LogicException('Refund not found.');
            if (!$this->native->exists($row)) {
                if (!empty($row['native_dispatch_at']) && strtotime($row['native_dispatch_at'] . ' UTC') < time() - 120) {
                    throw new \DomainException('native_refund_requires_review');
                }
                return 'pending';
            }
            $this->store->applied($row);
            return 'applied';
        } catch (\Throwable) {
            $this->store->mark($attemptId, 'review', 'refund_confirmation_requires_review');
            return 'review';
        }
    }
}
