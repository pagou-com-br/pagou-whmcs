<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;

/** Read-only snapshot. Payment processing remains in the webhook and worker flow. */
final class ClientPaymentStatus
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{status: string, state?: string, revision?: string, invoiceState?: string} */
    public function read(int $invoiceId, string $method, int $clientId, bool $authorizedAdmin): array
    {
        if ($invoiceId < 1 || !in_array($method, ['pix', 'boleto', 'card'], true) || ($clientId < 1 && !$authorizedAdmin)) {
            return ['status' => 'forbidden'];
        }
        $query = $this->pdo->prepare('SELECT userid, status FROM tblinvoices WHERE id = :id LIMIT 1');
        $query->execute(['id' => $invoiceId]);
        $invoice = $query->fetch(PDO::FETCH_ASSOC);
        if ($invoice === false || (!$authorizedAdmin && (int) $invoice['userid'] !== $clientId)) {
            return ['status' => 'not_found'];
        }
        $display = $this->display($invoiceId, $method, strtolower((string) $invoice['status']));
        if ($method === 'card') {
            $attempt = (new \Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardAttemptStore($this->pdo))->latestForInvoice($invoiceId);
            $display = ['state' => $attempt['status'] ?? 'not_started'];
        }

        return [
            'status' => 'ok',
            'state' => is_string($display['state'] ?? null) ? $display['state'] : 'pending',
            'revision' => hash('sha256', json_encode($display, JSON_THROW_ON_ERROR)),
            'invoiceState' => strtolower((string) $invoice['status']),
        ];
    }

    /** Shared by the invoice and its read-only status endpoint.
     * @return array<string,mixed> */
    public function display(int $invoiceId, string $method, ?string $invoiceState = null): array
    {
        $display = (new PaymentAttemptStore($this->pdo))->currentDisplayForInvoice($invoiceId, $method);
        if (
            in_array($method, ['pix', 'boleto'], true) && ($display['state'] ?? '') === 'paid'
            && !$this->paymentApplied($invoiceId, $method, (string) ($display['remoteId'] ?? ''))
        ) {
            // A provider status is not proof that the native payment was applied.
            $display['state'] = 'processing';
        }
        if ($method === 'pix') {
            $attempt = (new PaymentAttemptStore($this->pdo))->latestForInvoice($invoiceId, 'pix');
            $refund = $attempt === null ? [] : (new PixRefundView($this->pdo))->forAttempt($attempt);
            if (isset($refund['state'])) {
                $display['state'] = $refund['state'];
            }
        }
        if ($method === 'pix' && PaymentAttemptStore::pixExpired($display, $this->createdAt($invoiceId))) {
            // Expired codes are never offered; reloading the invoice issues a new Pix.
            $display = ['state' => 'expired', 'message' => 'Este Pix expirou e não pode mais ser pago. Atualize a página para gerar um novo código.'];
        }
        $invoiceState ??= $this->invoiceState($invoiceId);
        $state = (string) ($display['state'] ?? '');
        if (
            $invoiceState === 'cancelled' && !in_array($state, ['cancelled', 'canceled'], true)
            && !in_array($state, PaymentAttemptStore::RESULT_STATES, true)
        ) {
            // A cancelled invoice cannot be paid with a pending, replaced or never issued charge.
            $display = ['state' => 'cancelled'];
        }
        return $display;
    }

    private function createdAt(int $invoiceId): string
    {
        $attempt = (new PaymentAttemptStore($this->pdo))->latestForInvoice($invoiceId, 'pix');

        return (string) ($attempt['created_at'] ?? '');
    }

    private function invoiceState(int $invoiceId): string
    {
        $query = $this->pdo->prepare('SELECT status FROM tblinvoices WHERE id = :id LIMIT 1');
        $query->execute(['id' => $invoiceId]);

        return strtolower((string) ($query->fetchColumn() ?: ''));
    }

    private function paymentApplied(int $invoiceId, string $method, string $remoteId): bool
    {
        if ($remoteId === '') {
            return false;
        }
        $query = $this->pdo->prepare("SELECT * FROM pagou_ledger_entries WHERE invoice_id = ? AND entry_type = 'received_payment'");
        $query->execute([$invoiceId]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metadata = json_decode((string) $row['metadata_json'], true);
            if (($metadata['method'] ?? '') !== $method || ($metadata['remote_charge_id'] ?? '') !== $remoteId) {
                continue;
            }
            // Booked in WHMCS counts at once; WHMCS may still be running its InvoicePaid hooks.
            if (\Pagou\Whmcs\Payment\Ledger\Infrastructure\ReceiptBooking::booked($this->pdo, $row)) {
                return true;
            }
        }
        return false;
    }
}
