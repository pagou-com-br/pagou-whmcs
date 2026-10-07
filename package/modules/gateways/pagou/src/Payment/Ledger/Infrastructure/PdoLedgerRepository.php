<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger\Infrastructure;

use PDO;
use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\LedgerEntry;
use Pagou\Whmcs\Payment\Ledger\LedgerRepository;
use Pagou\Whmcs\Payment\Ledger\ReceivedPayment;

/**
 * Append-only adapter over the existing `pagou_ledger_entries` migration.
 *
 * The initial row is uniquely keyed by the economic key. State transitions are
 * journal rows whose idempotency keys are derived from that same key, giving
 * SQLite and MySQL the same duplicate-delivery semantics without new tables.
 */
final class PdoLedgerRepository implements LedgerRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly InvoiceClientResolver $clients,
        private readonly ?PdoReconciliationFindingRepository $findings = null,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function claim(ReceivedPayment $payment): LedgerEntry
    {
        $existing = $this->findInitial($payment->economicKey);
        if ($existing !== null) {
            return $this->hydrate($existing, $this->latestTransition($payment->economicKey));
        }

        $now = self::now();
        $metadata = $this->paymentMetadata($payment, LedgerEntry::RECEIVED);
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO pagou_ledger_entries '
                . '(id, idempotency_key, invoice_id, client_id, attempt_id, entry_type, amount_cents, currency, payment_at_utc, effective_at_utc, metadata_json, created_at) '
                . 'VALUES (:id, :idempotency_key, :invoice_id, :client_id, NULL, :entry_type, :amount_cents, :currency, :payment_at_utc, :effective_at_utc, :metadata_json, :created_at)'
            );
            $statement->execute([
                'id' => self::uuid(),
                'idempotency_key' => $payment->economicKey->value,
                'invoice_id' => $payment->invoiceId,
                'client_id' => $this->clients->clientIdForInvoice($payment->invoiceId),
                'entry_type' => 'received_payment',
                'amount_cents' => $payment->amountInCents,
                'currency' => 'BRL',
                'payment_at_utc' => $payment->paidAt->format('Y-m-d H:i:s.u'),
                'effective_at_utc' => $now,
                'metadata_json' => $metadata,
                'created_at' => $now,
            ]);
        } catch (\PDOException $exception) {
            if (!self::isUniqueViolation($exception)) {
                throw $exception;
            }
        }

        $stored = $this->findInitial($payment->economicKey);
        if ($stored === null) {
            throw new \RuntimeException('Ledger claim was not persisted.');
        }

        return $this->hydrate($stored, $this->latestTransition($payment->economicKey));
    }

    public function begin(EconomicPaymentKey $key): ?LedgerEntry
    {
        $initial = $this->findInitial($key);
        if ($initial === null) {
            return null;
        }
        $current = $this->hydrate($initial, $this->latestTransition($key));
        if ($current->status !== LedgerEntry::RECEIVED) {
            return null;
        }

        $transitionKey = self::transitionKey($key, LedgerEntry::APPLYING);
        try {
            $this->insertTransition($current->with(LedgerEntry::APPLYING, null, $current->attempts + 1), $transitionKey);
        } catch (\PDOException $exception) {
            if (!self::isUniqueViolation($exception)) {
                throw $exception;
            }
            return null;
        }

        return $current->with(LedgerEntry::APPLYING, null, $current->attempts + 1);
    }

    public function save(LedgerEntry $entry): void
    {
        $transitionKey = self::transitionKey($entry->key, $entry->status);
        try {
            $this->insertTransition($entry, $transitionKey);
        } catch (\PDOException $exception) {
            if (!self::isUniqueViolation($exception)) {
                throw $exception;
            }
        }

        if ($entry->status === LedgerEntry::QUARANTINED && $this->findings !== null) {
            $this->findings->quarantine($entry->key, $entry->finding ?? 'Payment is quarantined.');
        }
    }

    public function recoverable(): array
    {
        $statement = $this->pdo->query("SELECT * FROM pagou_ledger_entries WHERE entry_type = 'received_payment' ORDER BY created_at ASC");
        if ($statement === false) {
            return [];
        }
        $entries = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $key = $this->keyFromInitial($row);
            $entry = $this->hydrate($row, $this->latestTransition($key));
            if (in_array($entry->status, [LedgerEntry::RECEIVED, LedgerEntry::APPLYING], true)) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /** @return array<string,mixed>|null */
    private function findInitial(EconomicPaymentKey $key): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pagou_ledger_entries WHERE idempotency_key = :key AND entry_type = :type LIMIT 1');
        $statement->execute(['key' => $key->value, 'type' => 'received_payment']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function latestTransition(EconomicPaymentKey $key): ?array
    {
        $statement = $this->pdo->prepare("SELECT * FROM pagou_ledger_entries WHERE entry_type = 'received_payment_transition' AND metadata_json LIKE :needle ORDER BY created_at DESC");
        $statement->execute(['needle' => '%' . $key->value . '%']);
        $selected = null;
        $selectedPriority = -1;
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $metadata = $this->decode($row['metadata_json'] ?? null);
            if (($metadata['economic_key'] ?? null) === $key->value) {
                $priority = match ($metadata['status'] ?? null) {
                    LedgerEntry::APPLIED => 3,
                    LedgerEntry::QUARANTINED => 2,
                    LedgerEntry::APPLYING => 1,
                    default => 0,
                };
                if ($priority > $selectedPriority) {
                    $selected = $row;
                    $selectedPriority = $priority;
                }
            }
        }
        return $selected;
    }

    /**
     * @param array<string, mixed> $initial
     * @param array<string, mixed>|null $transition
     */
    private function hydrate(array $initial, ?array $transition): LedgerEntry
    {
        $metadata = $this->decode((string) $initial['metadata_json']);
        $key = $this->keyFromInitial($initial);
        $payment = new ReceivedPayment(
            $key,
            (string) $metadata['event_id'],
            (int) $initial['invoice_id'],
            (int) $initial['amount_cents'],
            (string) $metadata['provider'],
            (string) $metadata['remote_payment_id'],
            new \DateTimeImmutable((string) $initial['payment_at_utc'], new \DateTimeZone('UTC')),
            (string) $metadata['method'],
            isset($metadata['remote_charge_id']) ? (string) $metadata['remote_charge_id'] : null,
            isset($metadata['native_transaction_id']) ? (string) $metadata['native_transaction_id'] : null,
            isset($metadata['fee_cents']) ? (int) $metadata['fee_cents'] : null,
            isset($metadata['charge_amount_cents']) ? (int) $metadata['charge_amount_cents'] : null,
        );
        if ($transition === null) {
            return new LedgerEntry($key, $payment, LedgerEntry::RECEIVED, 0, null, new \DateTimeImmutable((string) $initial['created_at'], new \DateTimeZone('UTC')));
        }
        $state = $this->decode((string) $transition['metadata_json']);
        return new LedgerEntry(
            $key,
            $payment,
            (string) ($state['status'] ?? LedgerEntry::RECEIVED),
            (int) ($state['attempts'] ?? 0),
            isset($state['finding']) && is_string($state['finding']) ? $state['finding'] : null,
            new \DateTimeImmutable((string) $transition['created_at'], new \DateTimeZone('UTC')),
        );
    }

    /** @param array<string,mixed> $initial */
    private function keyFromInitial(array $initial): EconomicPaymentKey
    {
        $metadata = $this->decode((string) $initial['metadata_json']);
        return EconomicPaymentKey::fromRemotePayment(
            (int) $initial['invoice_id'],
            (string) $metadata['provider'],
            (string) $metadata['remote_payment_id'],
        );
    }

    private function insertTransition(LedgerEntry $entry, string $transitionKey): void
    {
        $now = self::now();
        $statement = $this->pdo->prepare(
            'INSERT INTO pagou_ledger_entries '
            . '(id, idempotency_key, invoice_id, client_id, attempt_id, entry_type, amount_cents, currency, payment_at_utc, effective_at_utc, metadata_json, created_at) '
            . 'VALUES (:id, :idempotency_key, :invoice_id, :client_id, NULL, :entry_type, :amount_cents, :currency, :payment_at_utc, :effective_at_utc, :metadata_json, :created_at)'
        );
        $statement->execute([
            'id' => self::uuid(),
            'idempotency_key' => $transitionKey,
            'invoice_id' => $entry->payment->invoiceId,
            'client_id' => $this->clients->clientIdForInvoice($entry->payment->invoiceId),
            'entry_type' => 'received_payment_transition',
            'amount_cents' => $entry->payment->amountInCents,
            'currency' => 'BRL',
            'payment_at_utc' => $entry->payment->paidAt->format('Y-m-d H:i:s.u'),
            'effective_at_utc' => $now,
            'metadata_json' => json_encode([
                'economic_key' => $entry->key->value,
                'status' => $entry->status,
                'attempts' => $entry->attempts,
                'finding' => $entry->finding,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);
    }

    private function paymentMetadata(ReceivedPayment $payment, string $status): string
    {
        return json_encode([
            'economic_key' => $payment->economicKey->value,
            'event_id' => $payment->eventId,
            'provider' => $payment->provider,
            'remote_payment_id' => $payment->remotePaymentId,
            'remote_charge_id' => $payment->remoteChargeId,
            'method' => $payment->method,
            'status' => $status,
        ] + array_filter([
            'native_transaction_id' => $payment->nativeTransactionId,
            'fee_cents' => $payment->feeInCents,
            'charge_amount_cents' => $payment->chargeAmountCents,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''), JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function decode(?string $json): array
    {
        if ($json === null) {
            throw new \RuntimeException('Ledger metadata is missing.');
        }
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \RuntimeException('Ledger metadata has an invalid shape.');
        }
        return $value;
    }

    private static function transitionKey(EconomicPaymentKey $key, string $status): string
    {
        return hash('sha256', 'pagou-ledger-transition-v1|' . $key->value . '|' . $status);
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
