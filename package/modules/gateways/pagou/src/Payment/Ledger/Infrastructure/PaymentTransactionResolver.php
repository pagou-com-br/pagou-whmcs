<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger\Infrastructure;

use PDO;
use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;

/** Resolves an original native receipt, never the latest attempt on an invoice. */
final class PaymentTransactionResolver
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function resolve(int $invoiceId, string $transactionId, string $method): array
    {
        if ($invoiceId < 1 || $transactionId === '' || !in_array($method, ['pix', 'card'], true)) {
            throw new \InvalidArgumentException('A transação original deve identificar a fatura e o meio.');
        }
        $native = $this->pdo->prepare('SELECT 1 FROM tblaccounts WHERE invoiceid = :invoice AND transid = :id AND amountin > 0 LIMIT 1');
        $native->execute(['invoice' => $invoiceId, 'id' => $transactionId]);
        if ($native->fetchColumn() === false) {
            throw new \LogicException('A transação não pertence à fatura informada.');
        }
        $resources = [];
        $ledger = $this->pdo->prepare("SELECT metadata_json FROM pagou_ledger_entries WHERE invoice_id = :invoice AND entry_type = 'received_payment'");
        $ledger->execute(['invoice' => $invoiceId]);
        foreach ($ledger->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $data = json_decode((string) $json, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['method'] ?? '') !== $method) {
                continue;
            }
            $identifiers = [$data['economic_key'] ?? '', $data['remote_payment_id'] ?? '', $data['native_transaction_id'] ?? ''];
            if (in_array($transactionId, $identifiers, true)) {
                $id = $data['remote_charge_id'] ?? ($method === 'card' ? ($data['remote_payment_id'] ?? '') : '');
                if (is_string($id) && $id !== '') {
                    $resources[$id] = true;
                }
            }
        }
        // Older Pix receipts can be correlated only through a verified delivery.
        if ($method === 'pix') {
            $deliveries = $this->pdo->prepare('SELECT w.payload_json FROM pagou_webhook_deliveries w INNER JOIN pagou_payment_attempts a ON a.id = w.attempt_id WHERE a.invoice_id = :invoice AND w.signature_valid = 1');
            $deliveries->execute(['invoice' => $invoiceId]);
            foreach ($deliveries->fetchAll(PDO::FETCH_COLUMN) as $json) {
                $body = json_decode((string) $json, true, 64, JSON_THROW_ON_ERROR);
                $event = (new \Pagou\Whmcs\Application\Webhook\WebhookEventParser())->parse((string) $json, 'refund-lookup');
                if ($event->paymentMethod !== 'pix' || $event->status !== 'paid') {
                    continue;
                }
                $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
                $paymentId = $data['transaction_id'] ?? null;
                $txid = $data['external_id'] ?? null;
                $matches = is_string($paymentId) && $paymentId !== '' && (
                    $paymentId === $transactionId
                    || (is_string($txid) && $txid !== '' && $txid === $transactionId)
                    || EconomicPaymentKey::fromRemotePayment($invoiceId, 'pagou', $paymentId)->value === $transactionId
                );
                if ($matches) {
                    $id = $data['id'] ?? null;
                    if (is_string($id) && $id !== '') {
                        $resources[$id] = true;
                    }
                }
            }
        }
        $query = $this->pdo->prepare('SELECT * FROM pagou_payment_attempts WHERE invoice_id = :invoice AND method = :method AND remote_id IS NOT NULL');
        $query->execute(['invoice' => $invoiceId, 'method' => $method]);
        $matches = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $attempt) {
            $id = (string) $attempt['remote_id'];
            if (isset($resources[$id]) || ($method === 'card' && ($transactionId === $id || $transactionId === EconomicPaymentKey::fromRemotePayment($invoiceId, 'pagou', $id)->value))) {
                $matches[] = $attempt;
            }
        }
        if (count($matches) !== 1) {
            throw new \LogicException('O vínculo do pagamento original exige conciliação antes do estorno.');
        }
        return $matches[0];
    }
}
