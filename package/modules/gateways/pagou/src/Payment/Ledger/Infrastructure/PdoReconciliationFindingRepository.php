<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger\Infrastructure;

use PDO;
use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;

/** Persists an operator-visible finding without touching WHMCS credit APIs. */
final class PdoReconciliationFindingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function quarantine(EconomicPaymentKey $key, string $message): void
    {
        $findingKey = hash('sha256', 'pagou-ledger-quarantine-v1|' . $key->value);
        $now = self::now();
        $details = json_encode([
            'economic_key' => $key->value,
            'message' => $message,
            'source' => 'received-payment-ledger',
        ], JSON_THROW_ON_ERROR);

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO pagou_reconciliation_findings '
                . '(id, finding_key, attempt_id, severity, finding_type, status, details_json, detected_at_utc, resolved_at_utc, resolved_by, resolution_json, created_at, updated_at, version) '
                . 'VALUES (:id, :finding_key, NULL, :severity, :finding_type, :status, :details_json, :detected_at_utc, NULL, NULL, NULL, :created_at, :updated_at, 1)'
            );
            $statement->execute([
                'id' => self::uuid(),
                'finding_key' => $findingKey,
                'severity' => 'high',
                'finding_type' => 'payment_application_failed',
                'status' => 'open',
                'details_json' => $details,
                'detected_at_utc' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\PDOException $exception) {
            if (!self::isUniqueViolation($exception)) {
                throw $exception;
            }
        }
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function isUniqueViolation(\PDOException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true) || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
