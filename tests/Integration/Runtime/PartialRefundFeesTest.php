<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\PartialRefundFees;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * WHMCS reverses the whole receipt fee on a gateway refund. Pagou keeps the fee
 * on a partial refund, so that row must end with no fee.
 */
final class PartialRefundFeesTest extends TestCase
{
    private PDO $pdo;
    /** @var list<array{string, array<string, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->pdo->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY AUTOINCREMENT, invoiceid INTEGER, userid INTEGER, transid TEXT, gateway TEXT, amountin DECIMAL(18,2), amountout DECIMAL(18,2), fees DECIMAL(18,2))');
        $native = $this->pdo->prepare('INSERT INTO tblaccounts (invoiceid, userid, transid, gateway, amountin, amountout, fees) VALUES (?, 7, ?, ?, ?, ?, ?)');
        $native->execute([48, 'pix-receipt', 'pagou_pix', '12.28', '0.00', '0.99']);
        $native->execute([48, 'refund-partial', 'pagou_pix', '0.00', '9.45', '-0.99']);
        $native->execute([45, 'refund-total', 'pagou_pix', '0.00', '9.55', '-0.99']);
        $native->execute([47, 'refund-other-amount', 'pagou_pix', '0.00', '5.00', '-0.99']);
        $refund = $this->pdo->prepare("INSERT INTO pagou_pix_refunds (id, attempt_id, invoice_id, client_id, remote_id, original_transaction_id, amount_cents, receipt_cents, status, actor_id, requested_at, updated_at, provider_refund_id) VALUES (?, ?, ?, 7, ?, 'pix-receipt', ?, ?, 'applied', 1, '2026-10-07 04:29:00', '2026-10-07 04:30:00', ?)");
        $refund->execute(['r1', 'a1', 48, 'pix-48', 945, 1228, 'refund-partial']);
        $refund->execute(['r2', 'a2', 45, 'pix-45', 955, 955, 'refund-total']);
        $refund->execute(['r3', 'a3', 47, 'pix-47', 522, 892, 'refund-other-amount']);
    }

    public function testClearsTheFeeOfAnExactPartialRefundOnly(): void
    {
        $fees = new PartialRefundFees($this->pdo, function (string $command, array $params): array {
            $this->calls[] = [$command, $params];
            $this->pdo->prepare('UPDATE tblaccounts SET fees = ? WHERE id = ?')->execute([$params['fees'], $params['transactionid']]);

            return ['result' => 'success'];
        });

        self::assertSame(1, $fees->correct());
        self::assertSame([['UpdateTransaction', ['transactionid' => 2, 'fees' => '0.00']]], $this->calls);
        self::assertSame(['0.99', '0', '-0.99', '-0.99'], array_map('strval', $this->pdo->query('SELECT fees FROM tblaccounts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
        // Nothing left to correct on the next worker run.
        self::assertSame(0, $fees->correct());
        self::assertCount(1, $this->calls);
    }

    public function testAZeroFeeIgnoredByTheApiIsClearedOnTheExactRow(): void
    {
        $fees = new PartialRefundFees($this->pdo, static fn (string $command, array $params): array => ['result' => 'success']);

        self::assertSame(1, $fees->correct(48));
        self::assertSame(0.0, (float) $this->pdo->query("SELECT fees FROM tblaccounts WHERE transid = 'refund-partial'")->fetchColumn());
        self::assertSame(0.99, (float) $this->pdo->query("SELECT fees FROM tblaccounts WHERE transid = 'pix-receipt'")->fetchColumn());
        self::assertSame(0, $fees->correct(45));
    }
}
