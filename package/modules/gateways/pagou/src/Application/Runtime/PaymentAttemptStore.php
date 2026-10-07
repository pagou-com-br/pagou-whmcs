<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;
use PDOException;
use Pagou\Whmcs\Support\Uuid;

final class PaymentAttemptStore
{
    /** Immediate Pix validity before which no replacement is made, beyond its provider expiry. */
    private const PIX_EXPIRY_MARGIN = 60;

    /** Validity used by earlier releases, for immediate Pix created before the expiry was stored. */
    private const LEGACY_PIX_VALIDITY = 86400;

    /**
     * Whether an immediate Pix display can no longer be paid. Due Pix (with a
     * due date) keep their own validity and are never treated as expired here.
     * @param array<string, mixed> $display
     */
    public static function pixExpired(array $display, string $createdAt, ?int $now = null): bool
    {
        if ((string) ($display['dueDate'] ?? '') !== '' || !in_array((string) ($display['state'] ?? ''), ['ready', 'pending', 'active'], true)) {
            return false;
        }
        $now ??= time();
        $expiresAt = (string) ($display['expiresAt'] ?? '');
        try {
            $limit = $expiresAt !== ''
                ? (new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')))->getTimestamp()
                : ($createdAt !== '' ? (new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC')))->getTimestamp() + self::LEGACY_PIX_VALIDITY : null);
        } catch (\Exception) {
            return false;
        }

        return $limit !== null && $now >= $limit + self::PIX_EXPIRY_MARGIN;
    }

    /** States whose charge must not be paid with the stored payment data. */
    public const CLOSED_STATES = ['cancel_requested', 'cancelled', 'canceled', 'expired', 'failed', 'superseded'];

    /** States rendered as a payment result instead of payment instructions. */
    public const RESULT_STATES = ['paid', 'processing', 'refund_pending', 'partially_refunded', 'refunded'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id:string,idempotency_key:string,created:bool} */
    public function ensureQueued(
        int $invoiceId,
        int $clientId,
        string $method,
        int $amountCents,
        int $revision = 1,
        ?string $dueDate = null,
    ): array {
        if ($invoiceId < 1 || $clientId < 1 || $amountCents < 1 || !in_array($method, ['pix', 'boleto'], true)) {
            throw new \InvalidArgumentException('Invalid payment attempt input.');
        }

        $idempotencyKey = hash('sha256', sprintf('whmcs:%d:%s:%d:%d', $invoiceId, $method, $revision, $amountCents));
        $existing = $this->byIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return ['id' => (string) $existing['id'], 'idempotency_key' => $idempotencyKey, 'created' => false];
        }

        $id = Uuid::v4();
        $now = $this->now();
        $request = json_encode(
            ['revision' => $revision, 'due_date' => $dueDate],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO pagou_payment_attempts '
                . '(id, invoice_id, client_id, gateway, method, status, amount_cents, currency, idempotency_key, '
                . 'economic_key, request_json, created_at, updated_at, version) '
                . 'VALUES (:id, :invoice_id, :client_id, :gateway, :method, :status, :amount_cents, :currency, '
                . ':idempotency_key, :economic_key, :request_json, :created_at, :updated_at, 1)'
            );
            $statement->execute([
                'id' => $id,
                'invoice_id' => $invoiceId,
                'client_id' => $clientId,
                'gateway' => 'pagou_' . $method,
                'method' => $method,
                'status' => 'queued',
                'amount_cents' => $amountCents,
                'currency' => 'BRL',
                'idempotency_key' => $idempotencyKey,
                'economic_key' => hash('sha256', sprintf('attempt:%d:%s:%d', $invoiceId, $method, $revision)),
                'request_json' => $request,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['19', '23000'], true)) {
                throw $exception;
            }
            $existing = $this->byIdempotencyKey($idempotencyKey);
            if ($existing === null) {
                throw $exception;
            }

            return ['id' => (string) $existing['id'], 'idempotency_key' => $idempotencyKey, 'created' => false];
        }

        return ['id' => $id, 'idempotency_key' => $idempotencyKey, 'created' => true];
    }

    /**
     * @return array{
     *   id:string,
     *   idempotency_key:string,
     *   created:bool,
     *   revision:int,
     *   superseded:list<array{id:string,method:string,remote_id:string}>
     * }
     */
    public function ensureCurrent(
        int $invoiceId,
        int $clientId,
        string $method,
        int $amountCents,
        ?string $dueDate = null,
    ): array {
        // Each method keeps its own charge: switching the invoice method reuses the
        // charge already issued for that method instead of replacing the others.
        $latest = $this->latestForInvoice($invoiceId, $method);
        $newest = $this->latestForInvoiceAnyMethod($invoiceId);
        $latestRequest = $latest === null ? [] : $this->decode((string) ($latest['request_json'] ?? ''));
        $newestRequest = $newest === null ? [] : $this->decode((string) ($newest['request_json'] ?? ''));
        $latestRevision = max(0, (int) ($newestRequest['revision'] ?? 0));
        $latestDueDate = isset($latestRequest['due_date']) && is_string($latestRequest['due_date'])
            ? $latestRequest['due_date']
            : null;
        $terminal = ['failed', 'cancelled', 'canceled', 'superseded', 'refunded'];

        if (
            $latest !== null
            && (string) $latest['method'] === $method
            && (int) $latest['amount_cents'] === $amountCents
            && $latestDueDate === $dueDate
            && !in_array((string) $latest['status'], $terminal, true)
        ) {
            return [
                'id' => (string) $latest['id'],
                'idempotency_key' => (string) $latest['idempotency_key'],
                'created' => false,
                'revision' => max(1, (int) ($latestRequest['revision'] ?? 1)),
                'superseded' => [],
            ];
        }

        $revision = $latestRevision + 1;
        $created = $this->ensureQueued($invoiceId, $clientId, $method, $amountCents, $revision, $dueDate);
        if (!$created['created']) {
            return $created + ['revision' => $revision, 'superseded' => []];
        }

        return $created + [
            'revision' => $revision,
            'superseded' => $this->supersedeActive($invoiceId, $created['id'], $method),
        ];
    }

    /**
     * Active charges of other methods, which stay payable while the invoice is open.
     * @return list<array<string, mixed>>
     */
    public function activeOtherMethods(int $invoiceId, string $method): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pagou_payment_attempts WHERE invoice_id = :invoice_id AND method <> :method '
            . "AND method IN ('pix', 'boleto') AND status NOT IN ('paid', 'cancelled', 'canceled', 'failed', 'refunded', 'superseded', 'cancel_requested', 'expired')");
        $statement->execute(['invoice_id' => $invoiceId, 'method' => $method]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retires the given active charges, as superseding does.
     * @param list<string> $attemptIds
     * @return list<array{id:string,method:string,remote_id:string}>
     */
    public function supersedeAttempts(int $invoiceId, array $attemptIds): array
    {
        $superseded = [];
        foreach ($attemptIds as $attemptId) {
            foreach ($this->supersedeActive($invoiceId, '', null, $attemptId) as $row) {
                $superseded[] = $row;
            }
        }

        return $superseded;
    }

    /** @return list<array{id:string,method:string,remote_id:string}> */
    public function supersedeInvoice(int $invoiceId): array
    {
        return $this->supersedeActive($invoiceId, '');
    }

    /** @return array<string, mixed>|null */
    public function find(string $attemptId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pagou_payment_attempts WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $attemptId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function latestForInvoice(int $invoiceId, string $method): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM pagou_payment_attempts WHERE invoice_id = :invoice_id AND method = :method '
            . 'ORDER BY created_at DESC LIMIT 1'
        );
        $statement->execute(['invoice_id' => $invoiceId, 'method' => $method]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function latestForInvoiceAnyMethod(int $invoiceId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM pagou_payment_attempts WHERE invoice_id = :invoice_id ORDER BY created_at DESC LIMIT 1'
        );
        $statement->execute(['invoice_id' => $invoiceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function markStatus(string $attemptId, string $status): void
    {
        if (!in_array($status, ['cancel_requested', 'cancelled', 'paid', 'superseded', 'failed', 'uncertain'], true)) {
            throw new \InvalidArgumentException('Invalid payment attempt status transition.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE pagou_payment_attempts SET status = :status, updated_at = :updated_at, '
            . 'version = version + 1 WHERE id = :id'
        );
        $statement->execute(['status' => $status, 'updated_at' => $this->now(), 'id' => $attemptId]);
        if ($statement->rowCount() !== 1) {
            throw new \OutOfBoundsException('Payment attempt was not found.');
        }
    }

    /** @return list<array{id:string,method:string,remote_id:string}> */
    private function supersedeActive(int $invoiceId, string $replacementId, ?string $method = null, ?string $onlyId = null): array
    {
        $parameters = ['invoice_id' => $invoiceId];
        $filter = '';
        if ($replacementId !== '') {
            $filter .= ' AND id <> :replacement_id';
            $parameters['replacement_id'] = $replacementId;
        }
        if ($method !== null) {
            $filter .= ' AND method = :method';
            $parameters['method'] = $method;
        }
        if ($onlyId !== null) {
            $filter .= ' AND id = :only_id';
            $parameters['only_id'] = $onlyId;
        }
        $sql = "SELECT id, method, remote_id FROM pagou_payment_attempts WHERE invoice_id = :invoice_id "
            . "AND status NOT IN ('paid', 'cancelled', 'canceled', 'failed', 'refunded', 'superseded')" . $filter;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $superseded = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $remoteId = is_string($row['remote_id'] ?? null) ? trim($row['remote_id']) : '';
            if ($remoteId !== '') {
                $superseded[] = [
                    'id' => (string) $row['id'],
                    'method' => (string) $row['method'],
                    'remote_id' => $remoteId,
                ];
            }
        }

        $updateSql = "UPDATE pagou_payment_attempts SET status = CASE "
            . "WHEN remote_id IS NULL OR remote_id = '' THEN 'superseded' ELSE 'cancel_requested' END, "
            . 'updated_at = :updated_at, '
            . 'version = version + 1 WHERE invoice_id = :invoice_id '
            . "AND status NOT IN ('paid', 'cancelled', 'canceled', 'failed', 'refunded', 'superseded')";
        $updateParameters = ['updated_at' => $this->now()] + $parameters;
        $updateSql .= $filter;
        $update = $this->pdo->prepare($updateSql);
        $update->execute($updateParameters);

        return $superseded;
    }

    /**
     * @param array<string, mixed> $display
     * @param array<string, mixed>|null $splitSnapshot
     */
    public function complete(
        string $attemptId,
        string $remoteId,
        string $status,
        array $display,
        ?array $splitSnapshot = null,
    ): void {
        $now = $this->now();
        $json = json_encode($display, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $splitJson = $splitSnapshot === null
            ? null
            : json_encode($splitSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->pdo->beginTransaction();
        try {
            $attempt = $this->find($attemptId);
            if ($attempt === null) {
                throw new \OutOfBoundsException('Payment attempt not found.');
            }
            $update = $this->pdo->prepare(
                'UPDATE pagou_payment_attempts SET remote_id = :remote_id, status = :status, '
                . 'split_snapshot_json = COALESCE(:split_snapshot, split_snapshot_json), '
                . 'updated_at = :updated_at, version = version + 1 WHERE id = :id'
            );
            $update->execute([
                'remote_id' => $remoteId,
                'status' => $status,
                'split_snapshot' => $splitJson,
                'updated_at' => $now,
                'id' => $attemptId,
            ]);
            $delete = $this->pdo->prepare('DELETE FROM pagou_payment_read_models WHERE attempt_id = :attempt_id');
            $delete->execute(['attempt_id' => $attemptId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO pagou_payment_read_models '
                . '(attempt_id, invoice_id, client_id, method, status, amount_cents, remote_id, display_json, '
                . 'refreshed_at_utc, version) VALUES (:attempt_id, :invoice_id, :client_id, :method, :status, '
                . ':amount_cents, :remote_id, :display_json, :refreshed_at_utc, 1)'
            );
            $insert->execute([
                'attempt_id' => $attemptId,
                'invoice_id' => $attempt['invoice_id'],
                'client_id' => $attempt['client_id'],
                'method' => $attempt['method'],
                'status' => $status,
                'amount_cents' => $attempt['amount_cents'],
                'remote_id' => $remoteId,
                'display_json' => $json,
                'refreshed_at_utc' => $now,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function displayForInvoice(int $invoiceId, string $method): array
    {
        $statement = $this->pdo->prepare(
            'SELECT display_json, remote_id FROM pagou_payment_read_models WHERE invoice_id = :invoice_id AND method = :method '
            . 'ORDER BY refreshed_at_utc DESC LIMIT 1'
        );
        $statement->execute(['invoice_id' => $invoiceId, 'method' => $method]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $json = $row === false ? null : $row['display_json'];
        if (!is_string($json) || $json === '') {
            $attempt = $this->latestForInvoice($invoiceId, $method);

            return ['state' => $attempt['status'] ?? 'pending'];
        }

        return $this->projection($row);
    }

    /**
     * Client display of the newest attempt. Cancellation and supersession update only the attempt
     * status, so the projection may still hold the payment data of a charge that must not be paid.
     *
     * @return array<string, mixed>
     */
    public function currentDisplayForInvoice(int $invoiceId, string $method): array
    {
        $newest = $this->latestForInvoice($invoiceId, $method);
        if ($newest === null) {
            return $this->displayForInvoice($invoiceId, $method);
        }
        $newestId = (string) $newest['id'];
        $row = $this->projectionRow(
            'r.attempt_id = :attempt_id',
            ['attempt_id' => $newestId],
        );
        $display = $row === null
            ? ['state' => (string) $newest['status']]
            : $this->withAttemptStatus($this->projection($row), (string) $newest['status']);
        if ($row !== null && !in_array((string) ($display['state'] ?? ''), self::CLOSED_STATES, true)) {
            return $display;
        }

        // A payment on an earlier charge keeps priority over a replacement that is closed or not issued yet.
        $earlier = $this->projectionRow(
            'r.invoice_id = :invoice_id AND r.method = :method AND r.attempt_id <> :attempt_id',
            ['invoice_id' => $invoiceId, 'method' => $method, 'attempt_id' => $newestId],
        );
        if ($earlier !== null) {
            $earlierDisplay = $this->withAttemptStatus($this->projection($earlier), (string) ($earlier['status'] ?? ''));
            if (in_array((string) ($earlierDisplay['state'] ?? ''), self::RESULT_STATES, true)) {
                return $earlierDisplay;
            }
        }

        return $display;
    }

    /**
     * @param array<string, mixed> $display
     * @return array<string, mixed>
     */
    private function withAttemptStatus(array $display, string $status): array
    {
        $state = (string) ($display['state'] ?? '');
        if (in_array($state, self::RESULT_STATES, true)) {
            return $display;
        }
        if (in_array($state, self::CLOSED_STATES, true)) {
            return array_intersect_key($display, ['state' => true, 'message' => true]);
        }
        if (in_array($status, self::CLOSED_STATES, true)) {
            return ['state' => $status];
        }
        if ($status === 'paid') {
            return ['state' => 'paid', 'remoteId' => (string) ($display['remoteId'] ?? '')];
        }

        return $display;
    }

    /**
     * @param array<string, string|int> $parameters
     * @return array<string, mixed>|null
     */
    private function projectionRow(string $condition, array $parameters): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.display_json, r.remote_id, a.status FROM pagou_payment_read_models r '
            . 'LEFT JOIN pagou_payment_attempts a ON a.id = r.attempt_id WHERE ' . $condition
            . ' ORDER BY r.refreshed_at_utc DESC LIMIT 1'
        );
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function projection(array $row): array
    {
        $json = $row['display_json'] ?? null;
        if (!is_string($json) || $json === '') {
            return ['state' => 'pending'];
        }
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($value)) {
                // Use the charge identity from the same projection, including existing invoices.
                $value['remoteId'] = (string) ($row['remote_id'] ?? '');
            }

            return is_array($value) ? $value : ['state' => 'pending'];
        } catch (\JsonException) {
            return ['state' => 'pending'];
        }
    }

    /** @return array<string, mixed>|null */
    private function byIdempotencyKey(string $idempotencyKey): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pagou_payment_attempts WHERE idempotency_key = :key LIMIT 1');
        $statement->execute(['key' => $idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($value) ? $value : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
