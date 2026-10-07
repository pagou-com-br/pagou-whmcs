<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger\Infrastructure;

use PDO;

/**
 * Whether a module receipt is already booked in WHMCS. WHMCS records the
 * payment and marks the invoice paid before it runs every InvoicePaid hook
 * inside the same call, so the ledger may record the end only minutes later.
 * The native payment row is the booking itself; a receipt under review is not
 * reported as booked.
 */
final class ReceiptBooking
{
    /** @param array<string, mixed> $receipt A received_payment ledger row. */
    public static function booked(PDO $pdo, array $receipt): bool
    {
        $key = (string) ($receipt['idempotency_key'] ?? '');
        if ($key === '') {
            return false;
        }
        $transition = $pdo->prepare("SELECT idempotency_key, metadata_json FROM pagou_ledger_entries WHERE entry_type = 'received_payment_transition' AND idempotency_key IN (?, ?)");
        $transition->execute([self::transition($key, 'applied'), self::transition($key, 'quarantined')]);
        $states = [];
        foreach ($transition->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metadata = json_decode((string) $row['metadata_json'], true);
            if (is_array($metadata) && ($metadata['economic_key'] ?? '') === $key) {
                $states[] = (string) ($metadata['status'] ?? '');
            }
        }
        if (in_array('applied', $states, true)) {
            return true;
        }
        if (in_array('quarantined', $states, true)) {
            return false;
        }
        $metadata = json_decode((string) ($receipt['metadata_json'] ?? ''), true);
        $identifiers = array_values(array_unique(array_filter([
            strtolower((string) (is_array($metadata) ? ($metadata['remote_payment_id'] ?? '') : '')),
            strtolower((string) (is_array($metadata) ? ($metadata['native_transaction_id'] ?? '') : '')),
        ], static fn (string $value): bool => $value !== '')));
        try {
            $native = $pdo->prepare(
                'SELECT COUNT(*) FROM tblaccounts WHERE invoiceid = ? AND amountin > 0 '
                . "AND gateway IN ('pagou_pix', 'pagou_boleto', 'pagou_creditcard') AND (transid = ?"
                . ($identifiers === [] ? '' : ' OR LOWER(transid) IN (' . implode(',', array_fill(0, count($identifiers), '?')) . ')') . ')'
            );
            $native->execute([(int) ($receipt['invoice_id'] ?? 0), $key, ...$identifiers]);

            return (int) $native->fetchColumn() === 1;
        } catch (\PDOException) {
            return false;
        }
    }

    private static function transition(string $key, string $status): string
    {
        return hash('sha256', 'pagou-ledger-transition-v1|' . $key . '|' . $status);
    }
}
