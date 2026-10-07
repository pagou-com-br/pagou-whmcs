<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;
use Pagou\Whmcs\Application\Async\OperationJob;

/** Optional measurements must never change the outcome of a payment. */
final class OperationTelemetry
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function apiTime(OperationJob $job, float $milliseconds): void
    {
        if ($job->leaseToken === null || !is_finite($milliseconds) || $milliseconds < 0) {
            return;
        }
        try {
            $read = $this->pdo->prepare("SELECT response_json FROM pagou_payment_operations WHERE id = :id AND lease_token = :token AND status = 'leased'");
            $read->execute(['id' => $job->id, 'token' => $job->leaseToken]);
            $row = $read->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return;
            }
            $result = json_decode((string) ($row['response_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
            $result = is_array($result) ? $result : [];
            $result['api_last_ms'] = round($milliseconds, 1);
            $write = $this->pdo->prepare("UPDATE pagou_payment_operations SET response_json = :result WHERE id = :id AND lease_token = :token AND status = 'leased'");
            $write->execute(['result' => json_encode($result, JSON_THROW_ON_ERROR), 'id' => $job->id, 'token' => $job->leaseToken]);
        } catch (\Throwable) {
            // No credentials, request paths, payloads or exception details are collected.
        }
    }
}
