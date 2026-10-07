<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\Infrastructure;

use PDO;

final class PdoCardCustomerStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(int $clientId, string $document): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT provider_customer_id, document_fingerprint FROM pagou_card_customers WHERE client_id = :client_id LIMIT 1'
        );
        $statement->execute(['client_id' => $clientId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !hash_equals((string) $row['document_fingerprint'], hash('sha256', $document))) {
            return null;
        }

        return (string) $row['provider_customer_id'];
    }

    public function save(int $clientId, string $providerCustomerId, string $document): void
    {
        $now = $this->now();
        $updateParameters = [
            'client_id' => $clientId,
            'provider_customer_id' => $providerCustomerId,
            'document_fingerprint' => hash('sha256', $document),
            'updated_at' => $now,
        ];
        $update = $this->pdo->prepare(
            'UPDATE pagou_card_customers SET provider_customer_id = :provider_customer_id, '
            . 'document_fingerprint = :document_fingerprint, updated_at = :updated_at, version = version + 1 '
            . 'WHERE client_id = :client_id'
        );
        $update->execute($updateParameters);
        if ($update->rowCount() > 0) {
            return;
        }
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO pagou_card_customers '
                . '(client_id, provider_customer_id, document_fingerprint, created_at, updated_at, version) '
                . 'VALUES (:client_id, :provider_customer_id, :document_fingerprint, :created_at, :updated_at, 1)'
            );
            $insert->execute([
                ...$updateParameters,
                'created_at' => $now,
            ]);
        } catch (\PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['19', '23000', '23505'], true)) {
                throw $exception;
            }
            $update->execute($updateParameters);
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
