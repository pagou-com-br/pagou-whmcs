<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;

/** Removes only disposable operational payloads and never deletes financial state. */
final class RetentionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{webhooks:int,operations:int} */
    public function run(int $operationalDays = 90, bool $dryRun = false): array
    {
        if ($operationalDays < 30 || $operationalDays > 730) {
            throw new \InvalidArgumentException('Operational retention must be between 30 and 730 days.');
        }
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . $operationalDays . ' days')
            ->format('Y-m-d H:i:s.u');
        $counts = [
            'webhooks' => $this->count(
                "SELECT COUNT(*) FROM pagou_webhook_deliveries WHERE received_at_utc < :cutoff AND payload_json <> '{}'",
                ['cutoff' => $cutoff],
            ),
            'operations' => $this->count(
                "SELECT COUNT(*) FROM pagou_payment_operations WHERE updated_at < :cutoff "
                . "AND status IN ('succeeded', 'failed') "
                . "AND (payload_json <> '{}' OR (operation_type NOT LIKE 'pix.%' AND (request_json IS NOT NULL OR response_json IS NOT NULL)) OR error_message IS NOT NULL)",
                ['cutoff' => $cutoff],
            ),
        ];
        if ($dryRun) {
            return $counts;
        }

        $webhooks = $this->pdo->prepare(
            "UPDATE pagou_webhook_deliveries SET payload_json = '{}' "
            . "WHERE received_at_utc < :cutoff AND payload_json <> '{}'"
        );
        $webhooks->execute(['cutoff' => $cutoff]);
        $operations = $this->pdo->prepare(
            "UPDATE pagou_payment_operations SET payload_json = '{}', request_json = CASE WHEN operation_type LIKE 'pix.%' THEN request_json ELSE NULL END, response_json = CASE WHEN operation_type LIKE 'pix.%' THEN response_json ELSE NULL END, "
            . "error_message = NULL WHERE updated_at < :cutoff AND status IN ('succeeded', 'failed')"
        );
        $operations->execute(['cutoff' => $cutoff]);
        return $counts;
    }

    /** @param array<string, scalar> $parameters */
    private function count(string $sql, array $parameters): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }
}
