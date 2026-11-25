<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;

/**
 * Picks the open invoices whose last boleto Pagou created but the bank refused or never
 * registered, so a new boleto reaches the customer without waiting for the invoice to be
 * opened. A bank outage can last a whole day (2026-11-22), so the waits grow with each refusal
 * in a row; after the fifth one the invoice is listed for the administrator instead.
 * Only module tables are scanned; the invoices are read afterwards by primary key, because
 * tblinvoices is MyISAM on some installations and a long read locks it for everyone.
 */
final class RefusedBoletoReissue
{
    /** Seconds to wait after the first, second, third and fourth refusal in a row. */
    public const WAITS = [1800, 7200, 21600, 86400];

    /** Boletos refused in a row before the invoice is listed: the first one and four new ones. */
    public const ATTEMPTS = 5;

    /** Older boletos no longer count and are never reissued. */
    private const WINDOW = '-3 days';

    private const CHUNK = 200;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{reissue: list<int>, exhausted: list<array{attempt_id: string, invoice_id: int}>}
     */
    public function due(\DateTimeImmutable $now, int $limit = 10): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, invoice_id, status, remote_id, updated_at FROM pagou_payment_attempts '
            . "WHERE method = 'boleto' AND created_at >= :since ORDER BY created_at ASC, id ASC"
        );
        $statement->execute(['since' => $now->modify(self::WINDOW)->format('Y-m-d H:i:s.u')]);
        $latest = [];
        $inRow = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $invoiceId = (int) $row['invoice_id'];
            $latest[$invoiceId] = $row;
            $inRow[$invoiceId] = self::refused($row) ? ($inRow[$invoiceId] ?? 0) + 1 : 0;
        }
        $candidates = [];
        foreach ($latest as $invoiceId => $row) {
            if ($inRow[$invoiceId] > 0) {
                $candidates[$invoiceId] = $row;
            }
        }
        $open = $this->openBoletoInvoices(array_keys($candidates));
        $reissue = [];
        $exhausted = [];
        foreach ($candidates as $invoiceId => $row) {
            if (!isset($open[$invoiceId])) {
                continue;
            }
            $refusals = $inRow[$invoiceId];
            if ($refusals >= self::ATTEMPTS) {
                $exhausted[] = ['attempt_id' => (string) $row['id'], 'invoice_id' => $invoiceId];
                continue;
            }
            $refusedAt = new \DateTimeImmutable((string) $row['updated_at'], new \DateTimeZone('UTC'));
            if ($now->getTimestamp() - $refusedAt->getTimestamp() >= self::WAITS[$refusals - 1] && count($reissue) < $limit) {
                $reissue[] = $invoiceId;
            }
        }

        return ['reissue' => $reissue, 'exhausted' => $exhausted];
    }

    /**
     * Created at Pagou and then given up: a refusal before the creation is the customer's data.
     * @param array<string, mixed> $row
     */
    private static function refused(array $row): bool
    {
        return (string) $row['status'] === 'failed' && trim((string) ($row['remote_id'] ?? '')) !== '';
    }

    /**
     * @param list<int> $invoiceIds
     * @return array<int, true> Unpaid invoices still set to the Pagou boleto.
     */
    private function openBoletoInvoices(array $invoiceIds): array
    {
        $open = [];
        foreach (array_chunk($invoiceIds, self::CHUNK) as $chunk) {
            $statement = $this->pdo->prepare('SELECT id, status, paymentmethod FROM tblinvoices WHERE id IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ')');
            $statement->execute($chunk);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
                if ((string) $invoice['status'] === 'Unpaid' && (string) $invoice['paymentmethod'] === 'pagou_boleto') {
                    $open[(int) $invoice['id']] = true;
                }
            }
        }

        return $open;
    }
}
