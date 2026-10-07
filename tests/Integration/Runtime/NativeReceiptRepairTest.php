<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\NativeReceiptRepair;
use Pagou\Whmcs\Application\Runtime\OperationalSettings;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class NativeReceiptRepairTest extends TestCase
{
    private PDO $pdo;
    private string $zone;
    /** @var list<array{string, array<string, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('America/Sao_Paulo');
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->pdo->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, invoiceid INTEGER, transid TEXT, gateway TEXT, date TEXT, fees TEXT, amountin TEXT, amountout TEXT)');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    public function testUtcDatesAndMissingPixFeesAreCorrectedOnceWithoutTouchingOtherRows(): void
    {
        $this->receipt(1, 'pix', 'receipt-1', 'charge-1', '2026-10-07 00:35:10.123456');
        $this->native(1, 'receipt-1', 'pagou_pix', '2026-10-07 00:35:10', '11.00');
        // An administrator already adjusted this date: it no longer matches the UTC booking.
        $this->receipt(2, 'pix', 'receipt-2', 'charge-2', '2026-10-06 22:25:00.000000');
        $this->native(2, 'RECEIPT-2', 'pagou_pix', '2026-10-06 19:30:00', '12.00');
        $this->receipt(3, 'boleto', 'receipt-3', 'charge-3', '2026-10-01 15:00:00.000000');
        $this->native(3, 'receipt-3', 'pagou_boleto', '2026-10-01 15:00:00', '30.00');
        // Another gateway with the same identifier is never touched.
        $this->native(1, 'receipt-x', 'mercadopago', '2026-10-07 00:35:10', '11.00');
        $fees = ['charge-1' => 99, 'charge-2' => 99];

        $result = $this->repair($fees)->run();

        self::assertSame(['dates' => 2, 'fees' => 2, 'complete' => true], $result);
        self::assertSame('2026-10-06 21:35:10', $this->column(1, 'pagou_pix', 'date'));
        self::assertSame('2026-10-06 19:30:00', $this->column(2, 'pagou_pix', 'date'));
        self::assertSame('2026-10-01 12:00:00', $this->column(3, 'pagou_boleto', 'date'));
        self::assertSame('2026-10-07 00:35:10', $this->column(1, 'mercadopago', 'date'));
        self::assertSame('0.99', $this->column(1, 'pagou_pix', 'fees'));
        self::assertSame('0.99', $this->column(2, 'pagou_pix', 'fees'));
        self::assertSame('0', $this->column(3, 'pagou_boleto', 'fees'));
        self::assertSame('11.00', $this->column(1, 'pagou_pix', 'amountin'));
        self::assertSame('receipt-1', $this->column(1, 'pagou_pix', 'transid'));
        self::assertSame(['UpdateTransaction', 'UpdateTransaction'], array_column($this->calls, 0));
        self::assertSame(['transactionid', 'fees'], array_keys($this->calls[0][1]));

        $this->calls = [];
        self::assertSame(['dates' => 0, 'fees' => 0, 'complete' => true], $this->repair($fees)->run());
        self::assertSame([], $this->calls);
    }

    public function testUnavailableFeeLookupFixesTheDateAndRetriesLater(): void
    {
        $this->receipt(1, 'pix', 'receipt-1', 'charge-1', '2026-10-07 00:35:10.000000');
        $this->native(1, 'receipt-1', 'pagou_pix', '2026-10-07 00:35:10', '11.00');
        $failing = new NativeReceiptRepair($this->pdo, fn (string $command, array $params): array => $this->api($command, $params), static function (): ?int {
            throw new \RuntimeException('API unavailable');
        });

        self::assertSame(['dates' => 1, 'fees' => 0, 'complete' => false], $failing->run());
        self::assertNull((new OperationalSettings($this->pdo))->get(NativeReceiptRepair::DONE));
        self::assertSame(['dates' => 0, 'fees' => 1, 'complete' => true], $this->repair(['charge-1' => 99])->run());
        self::assertSame('2026-10-06 21:35:10', $this->column(1, 'pagou_pix', 'date'));
    }

    public function testAmbiguousNativeRowsAndAFeeAboveTheAmountAreLeftUnchanged(): void
    {
        $this->receipt(1, 'pix', 'receipt-1', 'charge-1', '2026-10-07 00:35:10.000000');
        $this->native(1, 'receipt-1', 'pagou_pix', '2026-10-07 00:35:10', '11.00');
        $this->native(1, 'RECEIPT-1', 'pagou_pix', '2026-10-07 00:35:10', '11.00');
        $this->receipt(2, 'pix', 'receipt-2', 'charge-2', '2026-10-07 00:40:00.000000');
        $this->native(2, 'receipt-2', 'pagou_pix', '2026-10-06 21:40:00', '0.50');

        self::assertSame(['dates' => 0, 'fees' => 0, 'complete' => true], $this->repair(['charge-1' => 99, 'charge-2' => 99])->run());
        self::assertSame([], $this->calls);
    }

    /** @param array<string, int> $fees */
    private function repair(array $fees): NativeReceiptRepair
    {
        return new NativeReceiptRepair(
            $this->pdo,
            fn (string $command, array $params): array => $this->api($command, $params),
            static fn (string $charge): ?int => $fees[$charge] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function api(string $command, array $params): array
    {
        $this->calls[] = [$command, $params];
        if ($command === 'UpdateTransaction') {
            $this->pdo->prepare('UPDATE tblaccounts SET fees = ? WHERE id = ?')->execute([$params['fees'], $params['transactionid']]);
        }

        return ['result' => 'success'];
    }

    private function receipt(int $invoice, string $method, string $payment, string $charge, string $paidAt): void
    {
        $key = hash('sha256', 'economic-' . $payment);
        $this->pdo->prepare('INSERT INTO pagou_ledger_entries (id, idempotency_key, invoice_id, client_id, entry_type, amount_cents, currency, payment_at_utc, effective_at_utc, metadata_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$payment, $key, $invoice, 7, 'received_payment', 1100, 'BRL', $paidAt, $paidAt, json_encode(['economic_key' => $key, 'method' => $method, 'remote_payment_id' => $payment, 'remote_charge_id' => $charge], JSON_THROW_ON_ERROR), $paidAt]);
    }

    private function native(int $invoice, string $transid, string $gateway, string $date, string $amount): void
    {
        $this->pdo->prepare('INSERT INTO tblaccounts (invoiceid, transid, gateway, date, fees, amountin, amountout) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$invoice, $transid, $gateway, $date, '0', $amount, '0']);
    }

    private function column(int $invoice, string $gateway, string $column): string
    {
        $query = $this->pdo->prepare('SELECT ' . $column . ' FROM tblaccounts WHERE invoiceid = ? AND gateway = ? ORDER BY id LIMIT 1');
        $query->execute([$invoice, $gateway]);

        return (string) $query->fetchColumn();
    }
}
