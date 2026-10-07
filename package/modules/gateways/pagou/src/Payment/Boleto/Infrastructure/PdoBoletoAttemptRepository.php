<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Infrastructure;

use PDO;
use Pagou\Whmcs\Payment\Boleto\Contracts\BoletoAttemptRepository;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoArtifacts;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;
use Pagou\Whmcs\Payment\Split\PaymentSplitResponseMapper;

/** Persists boleto-specific state in the canonical module payment-attempt table. */
final class PdoBoletoAttemptRepository implements BoletoAttemptRepository
{
    public function __construct(private readonly PDO $pdo, private readonly int $clientId = 0)
    {
        if ($clientId < 0) {
            throw new \InvalidArgumentException('WHMCS client id must not be negative.');
        }
    }

    public function get(string $attemptId): ?BoletoAttempt
    {
        $statement = $this->pdo->prepare('SELECT * FROM pagou_payment_attempts WHERE id = :id AND method = :method LIMIT 1');
        $statement->execute(['id' => $attemptId, 'method' => 'boleto']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function save(BoletoAttempt $attempt): void
    {
        $existing = $this->get($attempt->id);
        $now = gmdate('Y-m-d H:i:s.u');
        $values = [
            'invoice_id' => $this->invoiceId($attempt->invoiceId),
            'client_id' => $this->clientId,
            'gateway' => 'pagou',
            'method' => 'boleto',
            'status' => $attempt->status,
            'amount_cents' => $attempt->amountCentavos,
            'currency' => 'BRL',
            'remote_id' => $attempt->remoteId,
            'idempotency_key' => $attempt->idempotencyKey,
            'economic_key' => hash('sha256', 'boleto:' . $attempt->invoiceId . ':' . $attempt->revision . ':' . $attempt->id),
            'request_json' => json_encode([
                'due_date' => $attempt->dueDate,
                'revision' => $attempt->revision,
                'supersedes_attempt_id' => $attempt->supersedesAttemptId,
                'superseded_by_attempt_id' => $attempt->supersededByAttemptId,
            ], JSON_THROW_ON_ERROR),
            'response_json' => json_encode([
                'artifacts' => $attempt->artifacts === null ? null : [
                    'digitable_line' => $attempt->artifacts->digitableLine,
                    'barcode' => $attempt->artifacts->barcode,
                    'pix_copy_paste' => $attempt->artifacts->pixCopyPaste,
                    'pix_qr_code' => $attempt->artifacts->pixQrCode,
                    'pdf_url' => $attempt->artifacts->pdfUrl,
                    'local_pdf_key' => $attempt->artifacts->localPdfKey,
                ],
                'failure_reason' => $attempt->failureReason,
            ], JSON_THROW_ON_ERROR),
            'split_snapshot_json' => $attempt->split === null
                ? null
                : json_encode($attempt->split->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];

        if ($existing === null) {
            $insert = $this->pdo->prepare('INSERT INTO pagou_payment_attempts (id, invoice_id, client_id, gateway, method, status, amount_cents, currency, remote_id, idempotency_key, economic_key, request_json, response_json, split_snapshot_json, created_at, updated_at) VALUES (:id, :invoice_id, :client_id, :gateway, :method, :status, :amount_cents, :currency, :remote_id, :idempotency_key, :economic_key, :request_json, :response_json, :split_snapshot_json, :created_at, :updated_at)');
            $insert->execute(['id' => $attempt->id] + $values + ['created_at' => $now, 'updated_at' => $now]);
            return;
        }

        $update = $this->pdo->prepare('UPDATE pagou_payment_attempts SET status = :status, remote_id = :remote_id, request_json = :request_json, response_json = :response_json, split_snapshot_json = COALESCE(:split_snapshot_json, split_snapshot_json), updated_at = :updated_at WHERE id = :id AND method = :method');
        $update->execute([
            'id' => $attempt->id,
            'method' => 'boleto',
            'status' => $values['status'],
            'remote_id' => $values['remote_id'],
            'request_json' => $values['request_json'],
            'response_json' => $values['response_json'],
            'split_snapshot_json' => $values['split_snapshot_json'],
            'updated_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): BoletoAttempt
    {
        $request = $this->decode((string) ($row['request_json'] ?? ''));
        $response = $this->decode((string) ($row['response_json'] ?? ''));
        $data = is_array($response['artifacts'] ?? null) ? $response['artifacts'] : null;
        $splitData = $this->decode((string) ($row['split_snapshot_json'] ?? ''));
        $split = $splitData === [] ? null : (new PaymentSplitResponseMapper())->projection($splitData);
        $artifacts = $data === null ? null : new BoletoArtifacts(
            $this->nullableString($data['digitable_line'] ?? null),
            $this->nullableString($data['barcode'] ?? null),
            $this->nullableString($data['pix_copy_paste'] ?? null),
            $this->nullableString($data['pix_qr_code'] ?? null),
            $this->nullableString($data['pdf_url'] ?? null),
            $this->nullableString($data['local_pdf_key'] ?? null),
        );
        return new BoletoAttempt(
            (string) $row['id'],
            (string) $row['invoice_id'],
            (int) ($request['revision'] ?? 1),
            (int) $row['amount_cents'],
            (string) ($request['due_date'] ?? ''),
            (string) $row['idempotency_key'],
            (string) $row['status'],
            $this->nullableString($row['remote_id'] ?? null),
            $artifacts,
            $this->nullableString($request['supersedes_attempt_id'] ?? null),
            $this->nullableString($request['superseded_by_attempt_id'] ?? null),
            $this->nullableString($response['failure_reason'] ?? null),
            $split,
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $value): array
    {
        if ($value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function invoiceId(string $value): int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new \InvalidArgumentException('WHMCS boleto invoice id must be numeric.');
        }
        return (int) $value;
    }
}
