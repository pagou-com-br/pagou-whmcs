<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Persistence\Async;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use PDOException;
use Pagou\Whmcs\Application\Async\JobPriority;
use Pagou\Whmcs\Application\Async\JobStatus;
use Pagou\Whmcs\Application\Async\Lease;
use Pagou\Whmcs\Application\Async\OperationJob;
use Pagou\Whmcs\Application\Async\OperationOutbox;
use Pagou\Whmcs\Application\Async\OperationType;

/**
 * Durable outbox backed by pagou_payment_operations. Leases are fenced with an
 * unguessable token and a monotonically increasing fencing token in the row.
 */
final class PdoOperationOutbox implements OperationOutbox
{
    private const TABLE = 'pagou_payment_operations';

    public function __construct(private readonly PDO $pdo, private readonly ?int $invoiceScope = null)
    {
        if ($invoiceScope !== null && $invoiceScope < 1) {
            throw new \InvalidArgumentException('Invoice scope must be positive.');
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function expeditePending(DateTimeImmutable $now): void
    {
        if ($this->invoiceScope === null) {
            return;
        }
        // Only successful "still processing" responses can be brought forward.
        // Network failures, Retry-After deadlines and live leases are untouched.
        $statement = $this->pdo->prepare("UPDATE " . self::TABLE
            . " SET available_at = :available_now, retry_after_utc = :retry_now, version = version + 1"
            . " WHERE status = 'retrying' AND error_message IN ('boleto_registration_pending','boleto_cancellation_pending')"
            . ' AND updated_at <= :cutoff AND available_at > :future' . $this->scopeSql());
        $statement->execute([
            'available_now' => $this->timestamp($now), 'retry_now' => $this->timestamp($now),
            'cutoff' => $this->timestamp($now->modify('-2 seconds')), 'future' => $this->timestamp($now),
        ]);
    }

    public function enqueue(OperationJob $job): bool
    {
        $sql = 'INSERT INTO ' . self::TABLE . ' (id, attempt_id, operation_type, status, deduplication_key, priority, payload_json, attempts, available_at, created_at, updated_at, version) VALUES (:id, :attempt_id, :type, :status, :deduplication_key, :priority, :payload_json, :attempts, :available_at, :created_at, :updated_at, 1)';
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([
                'id' => $job->id,
                'attempt_id' => is_string($job->payload['attempt_id'] ?? null) ? $job->payload['attempt_id'] : null,
                'type' => $job->type->value,
                'status' => $job->status->value,
                'deduplication_key' => hash('sha256', $job->deduplicationKey),
                'priority' => $job->priority->value,
                'payload_json' => $this->encode($job->payload),
                'attempts' => $job->attempts,
                'available_at' => $this->timestamp($job->availableAt),
                'created_at' => $this->timestamp($job->createdAt),
                'updated_at' => $this->timestamp($job->createdAt),
            ]);
            return true;
        } catch (PDOException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return false;
            }
            throw $exception;
        }
    }

    public function claim(string $workerId, DateTimeImmutable $now, DateInterval $leaseDuration): ?Lease
    {
        $this->releaseExpiredLeases($now);
        $expiresAt = $now->add($leaseDuration);
        $token = hash('sha256', $workerId . ':' . bin2hex(random_bytes(32)));
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            return $this->claimSqlite($token, $now, $expiresAt);
        }

        $this->pdo->beginTransaction();
        try {
            $row = $this->selectForClaim($now);
            if ($row === null) {
                $this->pdo->commit();
                return null;
            }
            $statement = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET status = :leased, attempts = attempts + 1, lease_token = :token, lease_expires_at = :expires, fencing_token = fencing_token + 1, started_at = :started_at, updated_at = :updated_at, version = version + 1 WHERE id = :id AND status IN (:queued_update, :retrying_update)');
            $statement->execute([
                'leased' => JobStatus::Leased->value,
                'token' => $token,
                'expires' => $this->timestamp($expiresAt),
                'started_at' => $this->timestamp($now),
                'updated_at' => $this->timestamp($now),
                'id' => $row['id'],
                'queued_update' => JobStatus::Queued->value,
                'retrying_update' => JobStatus::Retrying->value,
            ]);
            if ($statement->rowCount() !== 1) {
                $this->pdo->rollBack();
                return null;
            }
            $this->pdo->commit();
            $job = $this->find($row['id']);
            return $job === null ? null : new Lease($job, $token, $expiresAt);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function complete(string $jobId, string $leaseToken): bool
    {
        return $this->transition($jobId, $leaseToken, JobStatus::Succeeded, ['finished_at' => $this->timestamp($this->now())]);
    }

    public function retry(string $jobId, string $leaseToken, DateTimeImmutable $availableAt, string $reason, bool $countAttempt = true): bool
    {
        return $this->transition($jobId, $leaseToken, JobStatus::Retrying, [
            'available_at' => $this->timestamp($availableAt),
            'retry_after_utc' => $this->timestamp($availableAt),
            'error_message' => $reason,
        ], !$countAttempt);
    }

    public function markUncertain(string $jobId, string $leaseToken, string $reason): bool
    {
        return $this->transition($jobId, $leaseToken, JobStatus::Uncertain, ['error_code' => 'uncertain', 'error_message' => $reason]);
    }

    public function fail(string $jobId, string $leaseToken, string $reason): bool
    {
        return $this->transition($jobId, $leaseToken, JobStatus::Failed, ['error_message' => $reason, 'finished_at' => $this->timestamp($this->now())]);
    }

    public function releaseExpiredLeases(DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET status = :retrying, available_at = :available_now, retry_after_utc = :retry_now, lease_token = NULL, lease_expires_at = NULL, error_code = :code, error_message = :reason, updated_at = :updated_now, version = version + 1 WHERE status = :leased AND lease_expires_at <= :expired_now' . $this->scopeSql());
        $statement->execute([
            'retrying' => JobStatus::Retrying->value,
            'leased' => JobStatus::Leased->value,
            'available_now' => $this->timestamp($now),
            'retry_now' => $this->timestamp($now),
            'updated_now' => $this->timestamp($now),
            'expired_now' => $this->timestamp($now),
            'code' => 'lease_expired',
            'reason' => 'lease_expired',
        ]);
        return $statement->rowCount();
    }

    public function find(string $jobId): ?OperationJob
    {
        $statement = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $jobId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    private function claimSqlite(string $token, DateTimeImmutable $now, DateTimeImmutable $expiresAt): ?Lease
    {
        $sql = 'UPDATE ' . self::TABLE . ' SET status = :leased, attempts = attempts + 1, lease_token = :token, lease_expires_at = :expires, fencing_token = fencing_token + 1, started_at = :started_at, updated_at = :updated_at, version = version + 1 WHERE id = (SELECT id FROM ' . self::TABLE . ' WHERE status IN (:queued_select, :retrying_select) AND available_at <= :available_now' . $this->scopeSql() . ' ORDER BY priority DESC, created_at ASC, id ASC LIMIT 1) AND status IN (:queued_update, :retrying_update)';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'leased' => JobStatus::Leased->value,
            'token' => $token,
            'expires' => $this->timestamp($expiresAt),
            'started_at' => $this->timestamp($now),
            'updated_at' => $this->timestamp($now),
            'available_now' => $this->timestamp($now),
            'queued_select' => JobStatus::Queued->value,
            'retrying_select' => JobStatus::Retrying->value,
            'queued_update' => JobStatus::Queued->value,
            'retrying_update' => JobStatus::Retrying->value,
        ]);
        if ($statement->rowCount() !== 1) {
            return null;
        }
        $query = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE lease_token = :token LIMIT 1');
        $query->execute(['token' => $token]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : new Lease($this->hydrate($row), $token, $expiresAt);
    }

    /** @return array<string,mixed>|null */
    private function selectForClaim(DateTimeImmutable $now): ?array
    {
        $base = 'SELECT * FROM ' . self::TABLE . ' WHERE status IN (:queued, :retrying) AND available_at <= :now' . $this->scopeSql() . ' ORDER BY priority DESC, created_at ASC, id ASC LIMIT 1';
        $params = ['queued' => JobStatus::Queued->value, 'retrying' => JobStatus::Retrying->value, 'now' => $this->timestamp($now)];
        try {
            $statement = $this->pdo->prepare($base . ' FOR UPDATE SKIP LOCKED');
            $statement->execute($params);
        } catch (PDOException) {
            // MariaDB releases that do not support SKIP LOCKED remain safe by locking and waiting.
            $statement = $this->pdo->prepare($base . ' FOR UPDATE');
            $statement->execute($params);
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function scopeSql(): string
    {
        if ($this->invoiceScope === null) {
            return '';
        }
        // Interactive progress never processes another invoice, prepares PDFs, sends emails or performs maintenance.
        return ' AND attempt_id IN (SELECT id FROM pagou_payment_attempts WHERE invoice_id = '
            . $this->invoiceScope . ") AND operation_type IN ('issue_pix','cancel_pix','issue_boleto',"
            . "'cancel_boleto','replace_boleto','reconcile_payment','reconcile_uncertain_operation')";
    }

    /** @param array<string,string|null> $changes */
    private function transition(string $jobId, string $leaseToken, JobStatus $status, array $changes, bool $undoAttempt = false): bool
    {
        $changes += ['status' => $status->value, 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $this->timestamp($this->now())];
        $sets = [];
        foreach ($changes as $column => $value) {
            $sets[] = $column . ' = :' . $column;
        }
        if ($undoAttempt) {
            $sets[] = 'attempts = CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END';
        }
        $sets[] = 'version = version + 1';
        $sql = 'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = :id AND status = :leased AND lease_token = :fence AND lease_expires_at > :now';
        $changes += ['id' => $jobId, 'leased' => JobStatus::Leased->value, 'fence' => $leaseToken, 'now' => $this->timestamp($this->now())];
        $statement = $this->pdo->prepare($sql);
        $statement->execute($changes);
        return $statement->rowCount() === 1;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): OperationJob
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        return new OperationJob(
            (string) $row['id'],
            OperationType::from((string) $row['operation_type']),
            (string) $row['deduplication_key'],
            JobPriority::from((int) $row['priority']),
            $payload,
            $this->date((string) $row['created_at']),
            $this->date((string) $row['available_at']),
            JobStatus::from((string) $row['status']),
            (int) $row['attempts'],
            $row['lease_token'] === null ? null : (string) $row['lease_token'],
            $row['lease_expires_at'] === null ? null : $this->date((string) $row['lease_expires_at']),
            $row['error_message'] === null ? null : (string) $row['error_message'],
            $row['error_code'] === 'uncertain' ? (string) $row['error_message'] : null,
        );
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['19', '23000'], true);
    }

    private function timestamp(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
