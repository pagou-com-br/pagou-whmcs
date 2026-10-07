<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\RemoteInput;

use PDO;
use Pagou\Whmcs\Support\Uuid;

final class PdoRemoteInputSessionStore
{
    public function __construct(private readonly PDO $pdo, private readonly ?\Closure $nativeTransaction = null)
    {
    }

    /** @return array{id:string,secret:string} */
    public function issue(
        string $workflow,
        int $clientId,
        ?int $invoiceId,
        ?int $payMethodId,
        int $amountCents,
        string $currency,
        ?string $existingReference,
        int $installments = 1,
        int $ttlSeconds = 900,
    ): array {
        if (!in_array($workflow, ['payment', 'create', 'update'], true) || $clientId < 1) {
            throw new \InvalidArgumentException('A sessão de cartão informada é inválida.');
        }
        if ($workflow === 'payment' && ($invoiceId === null || $invoiceId < 1 || $amountCents < 1)) {
            throw new \InvalidArgumentException('A sessão de pagamento não possui uma fatura válida.');
        }
        if ($workflow === 'update' && ($payMethodId === null || $payMethodId < 1 || $existingReference === null)) {
            throw new \InvalidArgumentException('A sessão de atualização não possui uma referência válida.');
        }
        $id = Uuid::v4();
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $statement = $this->pdo->prepare(
            'INSERT INTO pagou_card_remote_input_sessions '
            . '(id, secret_hash, workflow, client_id, invoice_id, pay_method_id, amount_cents, currency, '
            . 'existing_reference, status, installments, expires_at_utc, created_at, updated_at, version) '
            . 'VALUES (:id, :secret_hash, :workflow, :client_id, :invoice_id, :pay_method_id, :amount_cents, '
            . ':currency, :existing_reference, :status, :installments, :expires_at, :created_at, :updated_at, 1)'
        );
        $statement->execute([
            'id' => $id,
            'secret_hash' => hash('sha256', $secret),
            'workflow' => $workflow,
            'client_id' => $clientId,
            'invoice_id' => $invoiceId,
            'pay_method_id' => $payMethodId,
            'amount_cents' => $amountCents,
            'currency' => strtoupper($currency),
            'existing_reference' => $existingReference,
            'status' => 'issued',
            'installments' => max(1, min(12, $installments)),
            'expires_at' => $now->modify('+' . max(60, min(1800, $ttlSeconds)) . ' seconds')->format('Y-m-d H:i:s.u'),
            'created_at' => $now->format('Y-m-d H:i:s.u'),
            'updated_at' => $now->format('Y-m-d H:i:s.u'),
        ]);

        return ['id' => $id, 'secret' => $secret];
    }

    /** @return array<string, mixed> */
    public function inspect(string $id, string $secret): array
    {
        $session = $this->find($id);
        $this->assertSecretAndExpiry($session, $secret);
        if (!in_array((string) $session['status'], ['issued', 'processing', 'completed'], true)) {
            throw new \RuntimeException('Esta sessão de cartão não está mais disponível.');
        }

        return $session;
    }

    /** @return array<string, mixed> */
    public function claim(string $id, string $secret): array
    {
        $session = $this->inspect($id, $secret);
        if ((string) $session['status'] === 'completed') {
            return $session;
        }
        if ((string) $session['status'] === 'processing') {
            throw new \RuntimeException('Esta operação de cartão já está em processamento.');
        }
        $now = $this->now();
        $statement = $this->pdo->prepare(
            "UPDATE pagou_card_remote_input_sessions SET status = 'processing', consumed_at_utc = :consumed_at, "
            . "updated_at = :now, version = version + 1 WHERE id = :id AND status = 'issued'"
        );
        $statement->execute(['consumed_at' => $now, 'now' => $now, 'id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Esta operação de cartão já foi utilizada.');
        }
        $session['status'] = 'processing';

        return $session;
    }

    /** @param array<string, mixed> $result */
    public function complete(string $id, array $result): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE pagou_card_remote_input_sessions SET status = 'completed', result_json = :result, "
            . 'updated_at = :now, version = version + 1 WHERE id = :id AND status = \'processing\''
        );
        $statement->execute([
            'result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'now' => $this->now(),
            'id' => $id,
        ]);
    }

    /** Commit the native Pay Method and its single-use result on the WHMCS connection.
     * @param array<string, mixed> $result
     * @param callable(): void $write
     */
    public function persistResult(string $id, array $result, callable $write): void
    {
        $save = function () use ($id, $result, $write): void {
            $lock = $this->pdo->prepare('SELECT status FROM pagou_card_remote_input_sessions WHERE id = :id'
                . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute(['id' => $id]);
            if ($lock->fetchColumn() !== 'processing') {
                throw new \RuntimeException('Esta sessão de cartão não está em processamento.');
            }
            $write();
            $this->complete($id, $result);
        };
        if ($this->nativeTransaction !== null) {
            // Capsule manages nested transactions used by native WHMCS helpers.
            ($this->nativeTransaction)($save);
            return;
        }
        $this->pdo->beginTransaction();
        try {
            $save();
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function fail(string $id): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE pagou_card_remote_input_sessions SET status = 'failed', updated_at = :now, "
            . "version = version + 1 WHERE id = :id AND status = 'processing'"
        );
        $statement->execute(['now' => $this->now(), 'id' => $id]);
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        if (preg_match('/^[a-f0-9-]{36}$/i', $id) !== 1) {
            throw new \InvalidArgumentException('A sessão de cartão é inválida.');
        }
        $statement = $this->pdo->prepare('SELECT * FROM pagou_card_remote_input_sessions WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $session = $statement->fetch(PDO::FETCH_ASSOC);
        if ($session === false) {
            throw new \OutOfBoundsException('A sessão de cartão não foi encontrada.');
        }

        return $session;
    }

    /** @param array<string, mixed> $session */
    private function assertSecretAndExpiry(array $session, string $secret): void
    {
        if ($secret === '' || !hash_equals((string) $session['secret_hash'], hash('sha256', $secret))) {
            throw new \RuntimeException('A autorização desta sessão de cartão é inválida.');
        }
        $expires = new \DateTimeImmutable((string) $session['expires_at_utc'], new \DateTimeZone('UTC'));
        if ($expires <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new \RuntimeException('A sessão de cartão expirou. Atualize a página e tente novamente.');
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
