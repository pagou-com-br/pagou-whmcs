<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger\Infrastructure;

use PDO;

final class PdoInvoiceClientResolver implements InvoiceClientResolver
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function clientIdForInvoice(int $invoiceId): int
    {
        $statement = $this->pdo->prepare('SELECT userid FROM tblinvoices WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $invoiceId]);
        $clientId = $statement->fetchColumn();
        if (!is_int($clientId) && !(is_string($clientId) && ctype_digit($clientId))) {
            throw new \OutOfBoundsException('The WHMCS invoice owner could not be resolved.');
        }

        return (int) $clientId;
    }
}
