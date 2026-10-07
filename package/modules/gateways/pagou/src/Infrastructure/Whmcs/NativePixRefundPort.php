<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Whmcs;

use PDO;
use Pagou\Whmcs\Domain\Money;

/** Read-only verification of receipts and refunds booked by WHMCS itself. */
final class NativePixRefundPort
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function receipt(int $invoiceId, int $accountId): ?array
    {
        $query = $this->pdo->prepare("SELECT * FROM tblaccounts WHERE invoiceid = ? AND id = ? AND gateway = 'pagou_pix' AND amountin > 0");
        $query->execute([$invoiceId, $accountId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function originalAmount(int $invoiceId, string $transactionId, int $clientId): int
    {
        $query = $this->pdo->prepare("SELECT t.amountin, t.amountout FROM tblaccounts t INNER JOIN tblinvoices i ON i.id = t.invoiceid AND i.userid = t.userid WHERE t.invoiceid = ? AND t.transid = ? AND t.userid = ? AND t.gateway = 'pagou_pix'");
        $query->execute([$invoiceId, $transactionId, $clientId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (
            count($rows) !== 1 || Money::fromDecimal((string) $rows[0]['amountout'])->centavos() !== 0
            || Money::fromDecimal((string) $rows[0]['amountin'])->centavos() < 1
        ) {
            throw new \DomainException('Não foi possível identificar um único recebimento Pix nesta fatura.');
        }
        return Money::fromDecimal((string) $rows[0]['amountin'])->centavos();
    }

    /** @param array<string,mixed> $refund */
    public function exists(array $refund): bool
    {
        $query = $this->pdo->prepare('SELECT invoiceid, userid, gateway, amountin, amountout FROM tblaccounts WHERE transid = ?');
        $query->execute([$refund['provider_refund_id']]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return false;
        }
        $row = $rows[0];
        if (
            count($rows) !== 1 || (int) $row['invoiceid'] !== (int) $refund['invoice_id']
            || (int) $row['userid'] !== (int) $refund['client_id'] || $row['gateway'] !== 'pagou_pix'
            || Money::fromDecimal((string) $row['amountin'])->centavos() !== 0
            || Money::fromDecimal((string) $row['amountout'])->centavos() !== (int) $refund['amount_cents']
        ) {
            throw new \DomainException('O identificador do reembolso possui um registro financeiro divergente.');
        }
        return true;
    }
}
