<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use Pagou\Payments\Admin\Widget\Findings;
use PDO;
use PHPUnit\Framework\TestCase;

final class DashboardSummaryTest extends TestCase
{
    public function testLocalFindingsAreReadIndependentlyFromAccountReceipts(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE pagou_reconciliation_findings (status TEXT)');
        $pdo->exec("INSERT INTO pagou_reconciliation_findings VALUES ('open'), ('pending'), ('resolved'), ('dismissed')");
        $before = $pdo->query('SELECT * FROM pagou_reconciliation_findings')->fetchAll();
        self::assertSame(['pending' => 2], (new Findings($pdo))->read());
        self::assertSame($before, $pdo->query('SELECT * FROM pagou_reconciliation_findings')->fetchAll());
    }
}
