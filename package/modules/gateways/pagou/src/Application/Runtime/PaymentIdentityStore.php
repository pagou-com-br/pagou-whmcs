<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;
use Pagou\Whmcs\Application\Webhook\WebhookEvent;
use Pagou\Whmcs\Domain\Document;
use Pagou\Whmcs\Domain\Money;

/** Minimal administrative history. Never a source of financial authorization. */
final class PaymentIdentityStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $payer
     * @return array<string,mixed> */
    public function snapshot(string $attempt, array $payer): array
    {
        $party = $this->party($payer);
        $this->insert('pagou_issued_parties', ['attempt_id' => $attempt, 'name' => $party['name'], 'document' => $party['document']]);
        $this->pdo->prepare("UPDATE pagou_issued_parties SET name = ?, document = ? WHERE attempt_id = ? AND name = '' AND document = ''")
            ->execute([$party['name'], $party['document'], $attempt]);
        // A retry keeps the identity sent on the first issuance attempt.
        $stored = $this->rows('SELECT name, document FROM pagou_issued_parties WHERE attempt_id = ?', [$attempt])[0];
        if ($stored['name'] !== '' && $stored['document'] !== '') {
            $payer['name'] = $stored['name'];
            $payer['document'] = $stored['document'];
        }
        return $payer;
    }

    /** Public GET response: payer is the issuance party, never the actual payer.
     * @param array<string,mixed> $raw */
    public function observeCharge(string $attempt, string $method, array $raw): void
    {
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : $raw;
        if (is_array($data['payer'] ?? null)) {
            $this->snapshot($attempt, $data['payer']);
        }
        if ($method === 'boleto') {
            $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
            $pix = $this->identifier($payload['qrcode_id'] ?? $data['qrcode_id'] ?? null);
            if ($pix !== '') {
                $this->link($attempt, $pix);
            }
        }
    }

    public function observeWebhook(WebhookEvent $event): void
    {
        $verified = $this->rows('SELECT signature_valid FROM pagou_webhook_deliveries WHERE event_key = ?', [$event->deliveryKey]);
        if ((int) ($verified[0]['signature_valid'] ?? 0) !== 1) {
            return;
        }
        $data = is_array($event->payload['data'] ?? null) ? $event->payload['data'] : [];
        if ($event->type === 'charge.created') {
            $pix = $this->identifier($data['qrcode_id'] ?? null);
            if ($pix !== '') {
                foreach ($this->rows("SELECT id FROM pagou_payment_attempts WHERE method = 'boleto' AND remote_id = ?", [$event->remoteId]) as $attempt) {
                    $this->link((string) $attempt['id'], $pix);
                }
            }
        }
        if ($event->type !== 'qrcode.completed' || $event->paymentMethod !== 'pix' || $event->status !== 'paid') {
            return;
        }
        $remote = $this->identifier($event->remoteId);
        $transaction = $this->identifier($data['transaction_id'] ?? null);
        if ($remote === '' || $transaction === '') {
            return;
        }
        try {
            $amount = $data['amount'] ?? null;
            if (!is_scalar($amount)) {
                return;
            }
            $money = Money::fromDecimal((string) $amount);
            if (!$money->isPositive()) {
                return;
            }
        } catch (\InvalidArgumentException) {
            return;
        }
        $party = $this->party(is_array($data['payer'] ?? null) ? $data['payer'] : []);
        $record = [
            'identity_key' => self::key($remote, $transaction), 'remote_id' => $remote,
            'transaction_id' => $transaction, 'amount_cents' => $money->centavos(),
            'name' => $party['name'], 'document' => $party['document'], 'e2e_id' => $this->identifier($data['e2e_id'] ?? null),
        ];
        $this->insert('pagou_pix_payers', $record);
        $stored = $this->rows('SELECT * FROM pagou_pix_payers WHERE identity_key = ?', [$record['identity_key']])[0];
        // A contradictory signed replay cannot silently replace confirmed identity.
        foreach (['remote_id', 'transaction_id', 'amount_cents', 'name', 'document', 'e2e_id'] as $field) {
            if ((string) $stored[$field] !== (string) $record[$field]) {
                $this->pdo->prepare('UPDATE pagou_pix_payers SET conflicted = 1 WHERE identity_key = ?')->execute([$record['identity_key']]);
                break;
            }
        }
    }

    /** Applied ledger rows only. Batch lookup, no association by name, document or amount alone.
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    public function enrich(array $records): array
    {
        if ($records === []) {
            return [];
        }
        $remotes = array_values(array_unique(array_column($records, 'pagouId')));
        $marks = implode(',', array_fill(0, count($remotes), '?'));
        $attempts = $this->rows('SELECT a.id, a.invoice_id, a.client_id, a.method, a.remote_id, p.name, p.document, p.pix_id, p.link_conflict '
            . 'FROM pagou_payment_attempts a LEFT JOIN pagou_issued_parties p ON p.attempt_id = a.id WHERE a.remote_id IN (' . $marks . ')', $remotes);
        $byCharge = [];
        foreach ($attempts as $attempt) {
            $byCharge[(string) $attempt['remote_id']][] = $attempt;
        }
        $keys = [];
        foreach ($records as $row) {
            foreach ($byCharge[$row['pagouId']] ?? [] as $attempt) {
                $pix = $attempt['method'] === 'pix' ? $attempt['remote_id'] : (($attempt['link_conflict'] ?? 0) == 0 ? ($attempt['pix_id'] ?? '') : '');
                $keys[] = self::key((string) $pix, (string) $row['reference']);
            }
        }
        $payers = [];
        foreach (array_chunk(array_unique($keys), 200) as $chunk) {
            foreach ($this->rows('SELECT * FROM pagou_pix_payers WHERE identity_key IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $payer) {
                $payers[$payer['identity_key']] = $payer;
            }
        }
        foreach ($records as &$row) {
            $row += ['issuedName' => '', 'issuedDocument' => '', 'payerName' => '', 'payerDocument' => '', 'e2e' => '', 'thirdParty' => false];
            $matching = array_values(array_filter($byCharge[$row['pagouId']] ?? [], static fn (array $a): bool =>
                (int) $a['invoice_id'] === (int) $row['invoice'] && (int) $a['client_id'] === (int) $row['client'] && $a['method'] === $row['method']));
            if (count($matching) !== 1) {
                continue;
            }
            $a = $matching[0];
            $row['issuedName'] = (string) ($a['name'] ?? '');
            $row['issuedDocument'] = (string) ($a['document'] ?? '');
            $pix = $a['method'] === 'pix' ? $a['remote_id'] : (($a['link_conflict'] ?? 0) == 0 ? ($a['pix_id'] ?? '') : '');
            $payer = $payers[self::key((string) $pix, (string) $row['reference'])] ?? null;
            if ($pix === '' || $payer === null || (int) $payer['conflicted'] !== 0 || (int) $payer['amount_cents'] !== (int) $row['amount']) {
                continue;
            }
            $row['payerName'] = $payer['name'];
            $row['payerDocument'] = $payer['document'];
            $row['e2e'] = $payer['e2e_id'];
            $row['thirdParty'] = $row['issuedDocument'] !== '' && $row['payerDocument'] !== '' && $row['issuedDocument'] !== $row['payerDocument'];
        }
        unset($row);
        return $records;
    }

    /**
     * @param string|null $method The invoice's method, whose open charge is the one shown.
     * @return list<array<string,mixed>> */
    public function forInvoice(int $invoice, ?string $method = null): array
    {
        $records = [];
        foreach ($this->rows("SELECT * FROM pagou_ledger_entries WHERE invoice_id = ? AND entry_type = 'received_payment' AND currency = 'BRL' ORDER BY payment_at_utc DESC LIMIT 100", [$invoice]) as $row) {
            if (!\Pagou\Whmcs\Payment\Ledger\Infrastructure\ReceiptBooking::booked($this->pdo, $row)) {
                continue;
            }
            $meta = json_decode((string) $row['metadata_json'], true);
            $records[] = ['invoice' => $invoice, 'client' => (int) $row['client_id'], 'method' => (string) ($meta['method'] ?? ''),
                'amount' => (int) $row['amount_cents'], 'reference' => (string) ($meta['remote_payment_id'] ?? ''),
                'pagouId' => (string) ($meta['remote_charge_id'] ?? ''), 'paid' => true,
                // The Transaction ID WHMCS shows for this receipt, as the search finds it.
                'transactionId' => (string) ($meta['native_transaction_id'] ?? '')];
        }
        $records = $this->enrich($records);
        foreach ($records as &$record) {
            if ($record['method'] === 'boleto' && $record['pagouId'] !== '') {
                $record['pixId'] = $this->embeddedPix($invoice, (string) $record['pagouId']);
            }
        }
        unset($record);
        if ($records !== []) {
            return $records;
        }
        // Unpaid: only the charge that counts now, the one of the invoice's method when
        // charges of other methods are still open. Cancelled or replaced attempts stay in the history.
        $current = "SELECT a.remote_id, a.method, p.name, p.document, p.pix_id, p.link_conflict FROM pagou_payment_attempts a INNER JOIN pagou_issued_parties p ON p.attempt_id = a.id "
            . "WHERE a.invoice_id = ? AND a.method IN ('pix', 'boleto') "
            . "ORDER BY CASE WHEN a.status IN ('cancelled', 'canceled', 'superseded', 'failed', 'cancel_requested') THEN 1 ELSE 0 END, "
            . 'CASE WHEN a.method = ? THEN 0 ELSE 1 END, a.created_at DESC, a.id DESC LIMIT 1';
        foreach ($this->rows($current, [$invoice, $method ?? '']) as $a) {
            $records[] = ['issuedName' => $a['name'], 'issuedDocument' => $a['document'], 'method' => $a['method'],
                'pagouId' => $a['remote_id'] ?? '', 'paid' => false,
                'pixId' => $a['method'] === 'boleto' && (int) ($a['link_conflict'] ?? 0) === 0 ? (string) ($a['pix_id'] ?? '') : ''];
        }
        return $records;
    }

    /** The Pix embedded in a boleto, as Pagou linked it when the boleto was issued. */
    private function embeddedPix(int $invoice, string $boleto): string
    {
        $sql = "SELECT p.pix_id FROM pagou_payment_attempts a INNER JOIN pagou_issued_parties p ON p.attempt_id = a.id "
            . "WHERE a.invoice_id = ? AND a.remote_id = ? AND a.method = 'boleto' AND p.link_conflict = 0 AND p.pix_id <> '' LIMIT 1";
        foreach ($this->rows($sql, [$invoice, $boleto]) as $row) {
            return (string) $row['pix_id'];
        }

        return '';
    }

    private function link(string $attempt, string $pix): void
    {
        $this->insert('pagou_issued_parties', ['attempt_id' => $attempt, 'name' => '', 'document' => '']);
        $this->pdo->prepare("UPDATE pagou_issued_parties SET link_conflict = 1 WHERE attempt_id = ? AND pix_id <> '' AND pix_id <> ?")->execute([$attempt, $pix]);
        $this->pdo->prepare("UPDATE pagou_issued_parties SET pix_id = ? WHERE attempt_id = ? AND pix_id = ''")->execute([$pix, $attempt]);
    }

    /** @param array<string,mixed> $input
     * @return array{name:string,document:string} */
    private function party(array $input): array
    {
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $name = preg_match('/^[^\x00-\x1f]{1,200}$/uD', $name) === 1 ? $name : '';
        $document = '';
        try {
            if (is_string($input['document'] ?? null) && preg_match('/^[0-9.\/ -]{11,18}$/D', $input['document']) === 1) {
                $document = Document::fromString($input['document'])->digits();
            }
        } catch (\InvalidArgumentException) {
            // Missing or malformed optional identification never blocks the payment.
        }
        return ['name' => $name, 'document' => $document];
    }

    private function identifier(mixed $value): string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $value) === 1 ? $value : '';
    }

    private static function key(string $remote, string $transaction): string
    {
        return hash('sha256', json_encode([$remote, $transaction], JSON_THROW_ON_ERROR));
    }

    /** @param array<string,string|int> $values */
    private function insert(string $table, array $values): void
    {
        $fields = array_keys($values);
        $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? ' ON CONFLICT DO NOTHING' : ' ON DUPLICATE KEY UPDATE ' . $fields[0] . ' = ' . $fields[0];
        $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')' . $suffix)->execute(array_values($values));
    }

    /** @param array<int,mixed> $params
     * @return list<array<string,mixed>> */
    private function rows(string $sql, array $params): array
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
