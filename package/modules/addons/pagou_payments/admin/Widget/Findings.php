<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\Widget;

use PDO;

/** Findings belong to this WHMCS installation, independently of account receipts. */
final class Findings
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{pending:int} */
    public function read(): array
    {
        $pending = $this->pdo->query("SELECT COUNT(*) FROM pagou_reconciliation_findings WHERE status IN ('open', 'pending')")->fetchColumn();
        return ['pending' => (int) $pending];
    }
}
