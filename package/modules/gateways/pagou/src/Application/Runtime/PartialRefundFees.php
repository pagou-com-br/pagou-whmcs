<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use Closure;
use PDO;
use Pagou\Whmcs\Domain\Money;

/**
 * Pagou gives the Pix fee back only on a total refund. WHMCS reverses the whole
 * receipt fee on a gateway refund, so a partial refund row must carry no fee.
 * Only rows matching a confirmed module refund exactly are touched; amounts,
 * identifiers and links are never changed.
 */
final class PartialRefundFees
{
    /** @var Closure(string, array<string, mixed>): array<string, mixed> */
    private readonly Closure $localApi;

    /** @param callable(string, array<string, mixed>): array<string, mixed> $localApi */
    public function __construct(private readonly PDO $pdo, callable $localApi)
    {
        $this->localApi = Closure::fromCallable($localApi);
    }

    /** @return int Refund rows whose fee was cleared. */
    public function correct(?int $invoiceId = null, int $limit = 20): int
    {
        $sql = "SELECT invoice_id, client_id, provider_refund_id, amount_cents FROM pagou_pix_refunds WHERE status = 'applied' "
            . 'AND provider_refund_id IS NOT NULL AND amount_cents < receipt_cents' . ($invoiceId !== null ? ' AND invoice_id = ?' : '')
            . ' ORDER BY updated_at DESC LIMIT ' . max(1, min(100, $limit));
        $refunds = $this->pdo->prepare($sql);
        $refunds->execute($invoiceId !== null ? [$invoiceId] : []);
        $corrected = 0;
        foreach ($refunds->fetchAll(PDO::FETCH_ASSOC) as $refund) {
            $native = $this->pdo->prepare('SELECT id, invoiceid, userid, gateway, amountin, amountout, fees FROM tblaccounts WHERE transid = ?');
            $native->execute([(string) $refund['provider_refund_id']]);
            $rows = $native->fetchAll(PDO::FETCH_ASSOC);
            $row = $rows[0] ?? null;
            if (
                count($rows) !== 1 || (int) $row['invoiceid'] !== (int) $refund['invoice_id'] || (int) $row['userid'] !== (int) $refund['client_id']
                || $row['gateway'] !== 'pagou_pix' || Money::fromDecimal((string) $row['amountin'])->centavos() !== 0
                || Money::fromDecimal((string) $row['amountout'])->centavos() !== (int) $refund['amount_cents']
                || round(abs((float) $row['fees']), 2) === 0.0
            ) {
                continue;
            }
            ($this->localApi)('UpdateTransaction', ['transactionid' => (int) $row['id'], 'fees' => '0.00']);
            // The documented API may ignore a zero fee; the exact row is then cleared directly.
            $clear = $this->pdo->prepare('UPDATE tblaccounts SET fees = 0 WHERE id = ? AND transid = ? AND fees <> 0');
            $clear->execute([(int) $row['id'], (string) $refund['provider_refund_id']]);
            $check = $this->pdo->prepare('SELECT fees FROM tblaccounts WHERE id = ?');
            $check->execute([(int) $row['id']]);
            if (round(abs((float) $check->fetchColumn()), 2) === 0.0) {
                ++$corrected;
            }
        }

        return $corrected;
    }
}
