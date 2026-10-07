<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reporting;

use PDO;
use Pagou\Whmcs\Domain\Document;

/**
 * Read-only lookup behind the addon search field. It only finds invoices with
 * records in this module, and reports which field matched, never the value.
 */
final class AdminSearch
{
    private const LIMIT = 20;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function search(string $term): array
    {
        $term = trim($term);
        if ($term === '' || $term === '!invalid') {
            return ['term' => '', 'results' => [], 'error' => $term === '' ? null : 'Use até 64 caracteres na busca.'];
        }
        if (preg_match('/[\x00-\x1f]/', $term) === 1) {
            return ['term' => '', 'results' => [], 'error' => 'A busca contém caracteres inválidos.'];
        }
        $matches = [];
        $digits = preg_replace('/\D/', '', $term) ?? '';
        $invoice = ltrim($term, '#');
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $invoice) === 1) {
            $this->add($matches, 'Número da fatura', $this->ids(
                'SELECT invoice_id FROM pagou_payment_attempts WHERE invoice_id = ? UNION SELECT invoice_id FROM pagou_ledger_entries WHERE invoice_id = ?',
                [(int) $invoice, (int) $invoice],
            ));
        } elseif (preg_match('/^[0-9.\/ -]{11,18}$/D', $term) === 1 && in_array(strlen($digits), [11, 14], true)) {
            try {
                $document = Document::fromString($term)->digits();
            } catch (\InvalidArgumentException) {
                return ['term' => $term, 'results' => [], 'error' => 'Informe um CPF ou CNPJ completo e válido.'];
            }
            $this->add($matches, 'CPF/CNPJ da emissão', $this->ids(
                'SELECT a.invoice_id FROM pagou_issued_parties p INNER JOIN pagou_payment_attempts a ON a.id = p.attempt_id WHERE p.document = ?',
                [$document],
            ));
            $this->add($matches, 'CPF/CNPJ de quem pagou', $this->ids(
                'SELECT a.invoice_id FROM pagou_pix_payers p INNER JOIN pagou_payment_attempts a ON a.remote_id = p.remote_id WHERE p.document = ?',
                [$document],
            ));
        } else {
            if (preg_match('/^E[0-9A-Za-z]{31}$/D', $term) === 1) {
                $this->add($matches, 'E2E do Pix', $this->ids(
                    'SELECT a.invoice_id FROM pagou_pix_payers p INNER JOIN pagou_payment_attempts a ON a.remote_id = p.remote_id WHERE p.e2e_id = ?',
                    [$term],
                ));
            }
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{3,127}$/D', $term) === 1) {
                $this->identifiers($matches, $term);
            }
            if (mb_strlen($term) >= 3 && preg_match('/\pL/u', $term) === 1) {
                $this->names($matches, $term);
            }
        }
        krsort($matches);

        return ['term' => $term, 'results' => $this->describe(array_slice($matches, 0, self::LIMIT, true)), 'more' => count($matches) > self::LIMIT, 'error' => null];
    }

    /** @param array<int,list<string>> $matches */
    private function identifiers(array &$matches, string $term): void
    {
        $this->add($matches, 'ID Pagou da cobrança', $this->ids('SELECT invoice_id FROM pagou_payment_attempts WHERE remote_id = ?', [$term]));
        $this->add($matches, 'ID do recebimento', $this->ids(
            'SELECT a.invoice_id FROM pagou_pix_payers p INNER JOIN pagou_payment_attempts a ON a.remote_id = p.remote_id WHERE p.transaction_id = ?',
            [$term],
        ));
        // New receipts use the Pagou receipt identifier as the WHMCS transaction ID.
        $this->add($matches, 'Transação no WHMCS', $this->ids(
            "SELECT invoiceid FROM tblaccounts WHERE LOWER(transid) = ? AND gateway IN ('pagou_pix', 'pagou_boleto', 'pagou_creditcard')",
            [strtolower($term)],
        ));
        $this->add($matches, 'Devolução Pix', $this->ids('SELECT invoice_id FROM pagou_pix_refunds WHERE provider_refund_id = ?', [$term]));
        $this->add($matches, 'Transação de cartão', $this->ids(
            'SELECT a.invoice_id FROM pagou_card_transactions t INNER JOIN pagou_payment_attempts a ON a.id = t.attempt_id WHERE t.provider_transaction_id = ?',
            [$term],
        ));
    }

    /** @param array<int,list<string>> $matches */
    private function names(array &$matches, string $term): void
    {
        $words = array_slice(array_values(array_filter(preg_split('/\s+/u', $term) ?: [], static fn (string $word): bool => $word !== '')), 0, 5);
        $where = [];
        $parameters = [];
        foreach ($words as $word) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word) . '%';
            $where[] = "(c.firstname LIKE ? ESCAPE '!' OR c.lastname LIKE ? ESCAPE '!' OR c.companyname LIKE ? ESCAPE '!')";
            array_push($parameters, $like, $like, $like);
        }
        $this->add($matches, 'Nome do cliente', $this->ids(
            'SELECT a.invoice_id FROM pagou_payment_attempts a INNER JOIN tblclients c ON c.id = a.client_id WHERE ' . implode(' AND ', $where),
            $parameters,
        ));
        $issued = [];
        foreach ($words as $word) {
            $issued[] = "p.name LIKE ? ESCAPE '!'";
        }
        $this->add($matches, 'Nome na emissão', $this->ids(
            'SELECT a.invoice_id FROM pagou_issued_parties p INNER JOIN pagou_payment_attempts a ON a.id = p.attempt_id WHERE ' . implode(' AND ', $issued),
            array_map(static fn (string $word): string => '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word) . '%', $words),
        ));
    }

    /**
     * @param array<int,list<string>> $matches
     * @return list<array<string,mixed>>
     */
    private function describe(array $matches): array
    {
        if ($matches === []) {
            return [];
        }
        $ids = array_keys($matches);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $invoices = [];
        try {
            $rows = $this->rows('SELECT i.id, i.userid, i.status, i.total, i.duedate, c.firstname, c.lastname, c.companyname '
                . 'FROM tblinvoices i LEFT JOIN tblclients c ON c.id = i.userid WHERE i.id IN (' . $marks . ')', $ids);
            foreach ($rows as $row) {
                $invoices[(int) $row['id']] = $row;
            }
        } catch (\PDOException) {
            // Without WHMCS tables the module history is still searchable.
        }
        $attempts = [];
        foreach ($this->rows('SELECT invoice_id, method, status, amount_cents, created_at FROM pagou_payment_attempts WHERE invoice_id IN (' . $marks . ') ORDER BY created_at DESC, id DESC', $ids) as $row) {
            $attempts[(int) $row['invoice_id']] ??= $row;
        }
        $results = [];
        foreach ($matches as $id => $fields) {
            $invoice = $invoices[$id] ?? null;
            $name = $invoice === null ? '' : trim((string) $invoice['companyname']);
            if ($invoice !== null && $name === '') {
                $name = trim((string) $invoice['firstname'] . ' ' . (string) $invoice['lastname']);
            }
            $attempt = $attempts[$id] ?? null;
            $results[] = [
                'invoice' => $id, 'client' => $invoice === null ? 0 : (int) $invoice['userid'], 'customer' => $name,
                'invoiceStatus' => $invoice === null ? '' : (string) $invoice['status'],
                'total' => $invoice === null ? null : (string) $invoice['total'],
                'dueDate' => $invoice === null ? '' : (string) $invoice['duedate'],
                'method' => $attempt === null ? '' : (string) $attempt['method'],
                'attemptStatus' => $attempt === null ? '' : (string) $attempt['status'],
                'amount' => $attempt === null ? null : (int) $attempt['amount_cents'],
                'matched' => $fields,
            ];
        }

        return $results;
    }

    /**
     * @param array<int,list<string>> $matches
     * @param list<int> $ids
     */
    private function add(array &$matches, string $field, array $ids): void
    {
        foreach ($ids as $id) {
            if (!in_array($field, $matches[$id] ?? [], true)) {
                $matches[$id][] = $field;
            }
        }
    }

    /**
     * @param list<scalar> $parameters
     * @return list<int>
     */
    private function ids(string $sql, array $parameters): array
    {
        try {
            $ids = array_map(static fn (array $row): int => (int) reset($row), $this->rows($sql . ' LIMIT 200', $parameters));
        } catch (\PDOException) {
            // A missing optional table (older install or WHMCS table) narrows the search, never breaks it.
            return [];
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param list<scalar> $parameters
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
