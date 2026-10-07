<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use DateTimeImmutable;
use PDO;
use Pagou\Payments\Admin\Http\AdminRequest;
use Pagou\Payments\Admin\ReportExport;
use Pagou\Payments\Admin\View\Dashboard;
use Pagou\Payments\Admin\View\MerchantReports;
use Pagou\Payments\Admin\View\ReportCharts;
use Pagou\Whmcs\Application\Reporting\AdminReportProvider;
use Pagou\Whmcs\Application\Reporting\MerchantReadModel;
use Pagou\Whmcs\Application\Reporting\ReportFilter;
use Pagou\Whmcs\Application\Runtime\PaymentAttemptStore;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class MerchantReportsTest extends TestCase
{
    private PDO $pdo;
    private MerchantReadModel $model;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($this->pdo))->migrate($migrations);
        $this->pdo->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT)');
        $this->pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, total TEXT, credit TEXT, status TEXT, duedate TEXT, paymentmethod TEXT)');
        $this->pdo->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, invoiceid INTEGER, amountin TEXT, amountout TEXT)');
        $this->pdo->exec("INSERT INTO tblclients VALUES (20, 'Ana', 'Silva', ''), (21, 'Outro', 'Cliente', '=SUM(1+1)')");
        $this->model = new MerchantReadModel($this->pdo, new DateTimeImmutable('2026-09-17T15:00:00Z'));
        $_SESSION = ['adminid' => 1, 'tkval' => 'test-csrf'];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testCustomerSearchIsSharedByReportAndCsvAndRejectsMalformedInput(): void
    {
        $this->receipt('match', 1, 20, 1234, 'pix', '2026-09-17 12:00:00', 'applied');
        $this->receipt('other', 2, 21, 9999, 'pix', '2026-09-17 12:00:00', 'applied');
        self::assertSame(1234, $this->model->report(['q' => 'ana'])['summary']['amount']);
        self::assertSame(0, $this->model->report(['q' => 'nobody'])['summary']['count']);
        $csv = (new ReportExport($this->pdo, ['q' => 'ana', 'from' => '2026-09-01', 'to' => '2026-09-17'], $this->exportRequest()))->contents();
        self::assertStringContainsString('Ana Silva', $csv);
        self::assertStringNotContainsString('SUM(1+1)', $csv);
        $this->expectException(\InvalidArgumentException::class);
        new ReportFilter(['q' => '!invalid']);
    }

    public function testDocumentSearchUsesHistoricalPartiesAndSharesFiltersWithCsvAndCharts(): void
    {
        $attempt = $this->invoice(101, 20, '12.34', '0.00', 'Paid', '2026-09-20');
        (new PaymentAttemptStore($this->pdo))->complete($attempt, 'charge-identified', 'paid', []);
        $store = new \Pagou\Whmcs\Application\Runtime\PaymentIdentityStore($this->pdo);
        $store->snapshot($attempt, ['name' => '<Emissão original>', 'document' => '52998224725']);
        $this->receipt('identified', 101, 20, 1234, 'pix', '2026-09-17 12:00:00', 'applied');
        $data = ['id' => 'charge-identified', 'transaction_id' => 'identified', 'amount' => '12.34', 'e2e_id' => 'E123',
            'payer' => ['name' => '=Nome do pagador', 'document' => '11144477735']];
        $body = json_encode(['name' => 'qrcode.completed', 'data' => $data], JSON_THROW_ON_ERROR);
        $inbox = new \Pagou\Whmcs\Infrastructure\Persistence\Webhook\PdoWebhookInbox($this->pdo);
        $inbox->reserve('synthetic-identity', new DateTimeImmutable());
        $store->observeWebhook((new \Pagou\Whmcs\Application\Webhook\WebhookEventParser())->parse($body, 'synthetic-identity'));
        $this->pdo->exec("UPDATE tblclients SET firstname = 'Nome novo'");
        $filters = ['document' => '111.444.777-35', 'party' => 'payer', 'from' => '2026-09-01', 'to' => '2026-09-17'];
        $report = $this->model->report($filters);
        self::assertSame(1234, $report['summary']['amount']);
        self::assertSame(1234, array_sum(array_column($report['daily'], 'amount')));
        self::assertSame('<Emissão original>', $report['rows'][0]['issuedName']);
        self::assertTrue($report['rows'][0]['thirdParty']);
        self::assertSame(0, $this->model->report(array_replace($filters, ['party' => 'issued']))['total']);
        self::assertSame(1, $this->model->report(['document' => '529.982.247-25', 'party' => 'either'])['total']);
        self::assertSame(0, $this->model->report(array_replace($filters, ['client' => '21']))['total']);
        $csv = (new ReportExport($this->pdo, $filters, $this->exportRequest()))->contents();
        self::assertStringContainsString("'=Nome do pagador", $csv);
        self::assertStringContainsString('11144477735', $csv);
        $html = (new MerchantReports())->report(['report' => $report], 'csrf');
        self::assertStringContainsString('&lt;Emissão original&gt;', $html);
        self::assertStringContainsString('Pago por outro CPF/CNPJ', $html);
        self::assertStringContainsString('name="document"', $html);
        self::assertStringNotContainsString('<Emissão original>', $html);
        // The receipt's WHMCS Transaction ID, the one the search finds, is shown with the other identifiers.
        $entry = $this->pdo->query("SELECT id, metadata_json FROM pagou_ledger_entries WHERE invoice_id = 101 AND entry_type = 'received_payment'")->fetch(PDO::FETCH_ASSOC);
        $meta = json_decode((string) $entry['metadata_json'], true) + ['native_transaction_id' => 'kk6g232xel65a0daee4dd13kk3098605132'];
        $this->pdo->prepare('UPDATE pagou_ledger_entries SET metadata_json = ? WHERE id = ?')->execute([json_encode($meta), $entry['id']]);
        $detail = $this->model->detail(101);
        self::assertSame('11144477735', $detail['identities'][0]['payerDocument']);
        self::assertSame('kk6g232xel65a0daee4dd13kk3098605132', $detail['identities'][0]['transactionId']);
        $page = (new MerchantReports())->detail(['detail' => $detail]);
        self::assertStringContainsString('E123', $page);
        self::assertMatchesRegularExpression('#<span>ID da transação</span><code id="[^"]+" title="kk6g232xel65a0daee4dd13kk3098605132">#', $page);
    }

    public function testInvalidDocumentNeverBroadensAReport(): void
    {
        foreach ([['document' => '123'], ['document' => '!invalid'], ['document' => '11144477735', 'party' => 'anything'], ['document' => '11144477735', 'report' => 'open']] as $input) {
            try {
                new ReportFilter($input);
                self::fail('Invalid identity filter was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testReceiptsUseAppliedIdentityPaidDateAndEqualPreviousPeriod(): void
    {
        $this->receipt('pix', 101, 20, 1234, 'pix', '2026-09-01 03:00:00', 'applied');
        $this->receipt('boleto', 102, 20, 2500, 'boleto', '2026-09-18 02:59:59', 'applied');
        $this->receipt('card', 103, 20, 5000, 'card', '2026-09-17 12:00:00', 'applied');
        $this->receipt('previous', 104, 20, 3000, 'pix', '2026-09-01 02:59:59', 'applied');
        $this->receipt('next', 105, 20, 9000, 'pix', '2026-09-18 03:00:00', 'applied');
        $this->receipt('quarantine', 106, 20, 9000, 'pix', '2026-09-10 12:00:00', 'quarantined');
        $this->receipt('pending', 107, 20, 9000, 'pix', '2026-09-10 12:00:00', 'applying');
        // A paid attempt is not financial proof and cannot inflate revenue.
        $attempt = (new PaymentAttemptStore($this->pdo))->ensureCurrent(108, 20, 'pix', 99999, '2026-09-17');
        (new PaymentAttemptStore($this->pdo))->markStatus($attempt['id'], 'paid');
        $report = $this->model->report([]);
        self::assertSame(3, $report['summary']['count']);
        self::assertSame(8734, $report['summary']['amount']);
        self::assertSame(2911, $report['summary']['average']);
        self::assertSame(3000, $report['previous']['amount']);
        self::assertSame(2500, $report['summary']['methods']['boleto']['amount']);
        self::assertSame(1234, $this->model->report(['method' => 'pix'])['summary']['amount']);
        self::assertSame(0, $this->model->report(['client' => '21'])['summary']['count']);
        $html = (new MerchantReports())->report(['report' => $report], '');
        self::assertStringContainsString('R$ 87,34', $html);
        self::assertStringContainsString('15/08/2026 00:00', $html);
        self::assertStringContainsString('31/08/2026 23:59', $html);
    }

    public function testOpenBalanceDeduplicatesAttemptsAndSubtractsCreditAndNetPayments(): void
    {
        $first = $this->invoice(1, 20, '100.01', '10.00', 'Unpaid', '2026-09-16');
        (new PaymentAttemptStore($this->pdo))->markStatus($first, 'superseded');
        (new PaymentAttemptStore($this->pdo))->ensureCurrent(1, 20, 'pix', 10001, '2026-09-16');
        $this->pdo->exec("INSERT INTO tblaccounts VALUES (1, 1, '25.01', '0.00'), (2, 1, '0.00', '5.00')");
        $this->invoice(2, 20, '90.00', '0', 'Paid', '2026-09-01');
        $this->invoice(3, 20, '50.00', '0', 'Unpaid', '2026-09-17');
        $this->invoice(4, 20, '30.00', '0', 'Unpaid', '2026-07-01');
        $this->invoice(5, 20, '900.00', '0', 'Unpaid', '2026-07-01', 'pagoupix');
        $this->invoice(6, 20, '20.00', '25', 'Unpaid', '2026-07-01');
        $this->pdo->exec("INSERT INTO tblinvoices VALUES (7,20,'8888','0','Unpaid','2026-09-01','pagou_pix')");
        $report = $this->model->report(['report' => 'open']);
        self::assertSame(3, $report['summary']['count']);
        self::assertSame(15000, $report['summary']['amount']);
        self::assertSame(7000, $report['summary']['buckets']['1 a 7 dias']['amount']);
        self::assertSame(5000, $report['summary']['buckets']['A vencer / hoje']['amount']);
        self::assertSame(3000, $report['summary']['buckets']['Mais de 60 dias']['amount']);
        self::assertSame(2, $this->model->report(['report' => 'open', 'status' => 'overdue'])['total']);
        self::assertSame(3, $this->model->report(['report' => 'open', 'from' => '2025-01-01', 'to' => '2025-01-31'])['total']);
        $chart = (new ReportCharts())->render($report, 'open');
        self::assertStringContainsString('Saldo por vencimento e atraso', $chart);
        self::assertStringContainsString('R$ 70,00', $chart);
        self::assertStringContainsString('R$ 50,00', $chart);
        self::assertStringContainsString('Mais de 60 dias', $chart);
    }

    public function testPendingFindsLedgerOnlyInvoiceAndDoesNotDuplicateExposure(): void
    {
        $attempt = $this->invoice(1, 20, '100', '0', 'Unpaid', '2026-09-17');
        $this->receipt('held', 1, 20, 12000, 'pix', '2026-09-15 12:00:00', 'quarantined');
        $this->finding('a', $attempt, []);
        $this->finding('b', null, ['economic_key' => hash('sha256', 'held')]);
        $this->finding('c', null, []);
        $this->finding('closed', $attempt, [], 'resolved');
        $report = $this->model->report(['report' => 'pending']);
        self::assertSame(3, $report['total']);
        self::assertSame(12000, $report['summary']['invoiceAmount']);
        self::assertSame(1, $report['summary']['unknown']);
        self::assertSame(2, $this->model->report(['report' => 'pending', 'invoice' => '1'])['total']);
        self::assertStringContainsString('antes de qualquer baixa manual', $report['rows'][0]['guidance']);
        self::assertSame(2, $report['rows'][0]['days']);
    }

    public function testPaginationExportsAllMatchingRowsAndEscapesFormulasWithoutLeakingPayloads(): void
    {
        for ($i = 1; $i <= 51; $i++) {
            $this->receipt('receipt-' . $i, $i, 21, 101, 'pix', '2026-09-10 12:00:00', 'applied');
        }
        $first = $this->model->report([]);
        $second = $this->model->report(['page' => '2']);
        self::assertCount(50, $first['rows']);
        self::assertCount(1, $second['rows']);
        self::assertSame(5151, $second['summary']['amount']);
        self::assertNotContains($second['rows'][0], $first['rows']);
        $export = new ReportExport($this->pdo, ['from' => '2026-09-01', 'to' => '2026-09-17', 'page' => '2', 'client' => '21'], $this->exportRequest());
        $csv = $export->contents();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertSame(52, substr_count($csv, "\r\n"));
        self::assertStringContainsString("'=SUM(1+1)", $csv);
        self::assertStringContainsString(';1,01;', $csv);
        self::assertStringNotContainsString('SECRET-PAYLOAD', $csv);
        self::assertSame(1, substr_count((new ReportExport($this->pdo, ['from' => '2026-09-01', 'to' => '2026-09-17', 'client' => '20'], $this->exportRequest()))->contents(), "\r\n"));
    }

    public function testReportChartsUseAllFilteredRowsBeforePagination(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->receipt('chart-' . $i, $i, 20, 101, 'pix', $i <= 28 ? '2026-09-17 02:30:00' : '2026-09-17 03:00:00', 'applied');
        }
        $this->receipt('other-client', 56, 21, 99999, 'pix', '2026-09-17 12:00:00', 'applied');
        $this->receipt('other-method', 57, 20, 99999, 'boleto', '2026-09-17 12:00:00', 'applied');
        $this->receipt('other-date', 58, 20, 99999, 'pix', '2026-09-15 02:30:00', 'applied');
        $this->receipt('unapplied', 59, 20, 99999, 'pix', '2026-09-17 12:00:00', 'quarantined');
        $filters = ['from' => '2026-09-15', 'to' => '2026-09-17', 'client' => '20', 'method' => 'pix', 'q' => 'ana', 'status' => 'applied'];
        $first = $this->model->report($filters);
        $second = $this->model->report($filters + ['page' => '2']);

        self::assertCount(5, $second['rows']);
        self::assertSame($first['daily'], $second['daily']);
        self::assertSame([
            ['date' => '2026-09-15', 'amount' => 0, 'count' => 0],
            ['date' => '2026-09-16', 'amount' => 2828, 'count' => 28],
            ['date' => '2026-09-17', 'amount' => 2727, 'count' => 27],
        ], $second['daily']);
        self::assertSame($second['summary']['amount'], array_sum(array_column($second['daily'], 'amount')));
        self::assertSame($second['total'], array_sum(array_column($second['daily'], 'count')));
        $invoice = $this->model->report($filters + ['invoice' => '1']);
        self::assertSame(101, array_sum(array_column($invoice['daily'], 'amount')));
        $html = (new ReportCharts())->render($second, 'receipts');
        self::assertStringContainsString('R$ 28,28', $html);
        self::assertStringContainsString('28 recebimento(s)', $html);
        self::assertStringNotContainsString('Hoje', $html);
        self::assertStringNotContainsString('SECRET-PAYLOAD', $html);
    }

    public function testCategoryChartsRespectFiltersAndCountUnknownAmountFindings(): void
    {
        $first = $this->invoice(1, 20, '100', '0', 'Unpaid', '2026-09-17');
        $store = new PaymentAttemptStore($this->pdo);
        $store->markStatus($first, 'superseded');
        $replacement = $store->ensureCurrent(1, 20, 'pix', 10000, '2026-09-17');
        $store->markStatus($replacement['id'], 'paid');
        $other = $this->invoice(2, 21, '999', '0', 'Unpaid', '2026-09-17');
        $store->markStatus($other, 'failed');
        $this->pdo->exec("UPDATE pagou_payment_attempts SET created_at = '2026-09-17 12:00:00'");
        $attempts = $this->model->report(['report' => 'attempts', 'client' => '20', 'invoice' => '1', 'method' => 'pix', 'q' => 'ana']);
        self::assertSame(1, $attempts['summary']['statuses']['superseded']);
        self::assertSame(1, $attempts['summary']['statuses']['paid']);
        self::assertSame(2, array_sum($attempts['summary']['statuses']));
        self::assertSame(['paid' => 1], $this->model->report(['report' => 'attempts', 'status' => 'paid'])['summary']['statuses']);
        $chart = (new ReportCharts())->render($attempts, 'attempts');
        self::assertStringContainsString('Substituída', $chart);
        self::assertStringContainsString('Paga', $chart);
        self::assertStringNotContainsString('R$', $chart);

        $this->finding('linked', $replacement['id'], []);
        $this->finding('unknown', null, []);
        $this->finding('amount', $replacement['id'], []);
        $this->finding('closed', $replacement['id'], [], 'resolved');
        $this->pdo->exec("UPDATE pagou_reconciliation_findings SET finding_type = 'amount_mismatch', status = 'pending' WHERE id = 'amount'");
        $pending = $this->model->report(['report' => 'pending']);
        self::assertSame(2, $pending['summary']['types']['payment_application_failed']);
        self::assertSame(1, $pending['summary']['types']['amount_mismatch']);
        self::assertSame(3, array_sum($pending['summary']['types']));
        self::assertSame(1, $pending['summary']['unknown']);
        $filtered = $this->model->report(['report' => 'pending', 'invoice' => '1', 'status' => 'pending']);
        self::assertSame(['amount_mismatch' => 1], $filtered['summary']['types']);
        self::assertStringContainsString('Valor divergente', (new ReportCharts())->render($filtered, 'pending'));
    }

    public function testChartsHandleEmptySingleDayAndMaximumPeriodWithoutInventingToday(): void
    {
        foreach (['receipts', 'open', 'attempts', 'pending'] as $kind) {
            $html = (new ReportCharts())->render($this->model->report(['report' => $kind]), $kind);
            self::assertStringContainsString('Nenhum', $html);
            self::assertStringNotContainsString('pagou-report-bars', $html);
            self::assertStringNotContainsString('data-pagou-trend', $html);
        }
        $this->receipt('one-day', 1, 20, 123, 'pix', '2026-09-17 12:00:00', 'applied');
        $single = $this->model->report(['from' => '2026-09-17', 'to' => '2026-09-17']);
        self::assertCount(1, $single['daily']);
        self::assertSame(1, substr_count((new ReportCharts())->render($single, 'receipts'), 'data-count="'));
        $long = $this->model->report(['from' => '2025-09-17', 'to' => '2026-09-17']);
        self::assertCount(366, $long['daily']);
        $chart = (new ReportCharts())->render($long, 'receipts');
        self::assertSame(366, substr_count($chart, 'data-count="'));
        self::assertStringContainsString('pagou-trend-bars is-dense', $chart);
        self::assertStringContainsString('Ver os valores em tabela', $chart);
        self::assertStringNotContainsString('Hoje', $chart);
    }

    public function testExportRejectsUnauthenticatedAndInvalidCsrfBeforeDatabaseRead(): void
    {
        $this->pdo->exec('DROP TABLE pagou_ledger_entries');
        foreach ([['adminid' => 0, 'tkval' => 'test-csrf'], ['adminid' => 1, 'tkval' => 'different']] as $session) {
            $_SESSION = $session;
            try {
                new ReportExport($this->pdo, [], $this->exportRequest());
                self::fail('Export must require admin and CSRF.');
            } catch (\RuntimeException $error) {
                self::assertNotInstanceOf(\PDOException::class, $error);
            }
        }
    }

    public function testErrorsDoNotBecomeZeroRevenueAndInvalidFiltersAreNotSilentlyIgnored(): void
    {
        foreach ([['from' => '2026-02-30'], ['client' => '0 OR 1=1'], ['report' => 'open', 'status' => 'applied'], ['from' => '2024-01-01', 'to' => '2026-01-01']] as $filters) {
            try {
                new ReportFilter($filters);
                self::fail('Invalid report filters must fail.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->pdo->exec('DROP TABLE pagou_ledger_entries');
        $result = (new AdminReportProvider($this->pdo))->admin('reports', []);
        self::assertArrayHasKey('reportError', $result);
        self::assertArrayNotHasKey('report', $result);
        self::assertStringNotContainsString('pagou_ledger_entries', $result['reportError']);
        self::assertStringNotContainsString('R$ 0,00', (new MerchantReports())->report($result, ''));
        self::assertStringNotContainsString('pagou-report-chart', (new MerchantReports())->report($result, ''));
    }

    public function testDetailUsesExplicitLinksAndShowsUncertainRefundWithoutClaimingCompletion(): void
    {
        $attempt = $this->invoice(1, 20, '100', '0', 'Unpaid', '2026-09-17');
        $this->receipt('paid', 1, 20, 10000, 'pix', '2026-09-17 12:00:00', 'applied');
        $this->receipt('unrelated', 2, 21, 500, 'pix', '2026-09-17 12:00:00', 'applied');
        $this->pdo->prepare('INSERT INTO pagou_card_transactions (id, attempt_id, capture_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')->execute(['card-1', $attempt, 'captured', '2026-09-17 12:00:00', '2026-09-17 12:00:00']);
        $this->pdo->exec("INSERT INTO pagou_card_refunds (id,card_transaction_id,idempotency_key,amount_cents,status,requested_at_utc,created_at,updated_at,response_json) VALUES ('refund-1','card-1','refund-key',3000,'uncertain','2026-09-17 13:00:00','2026-09-17 13:00:00','2026-09-17 13:00:00','SECRET-PAYLOAD')");
        $detail = $this->model->detail(1);
        self::assertCount(2, $detail['ledger']);
        self::assertCount(1, $detail['refunds']);
        self::assertSame('uncertain', $detail['refunds'][0]['status']);
        $html = (new MerchantReports())->detail(['detail' => $detail]);
        self::assertStringContainsString('Resultado incerto', $html);
        self::assertStringContainsString('R$ 30,00', $html);
        self::assertStringNotContainsString('SECRET-PAYLOAD', $html);
        self::assertStringNotContainsString('unrelated', $html);
        $this->expectException(\InvalidArgumentException::class);
        $this->model->detail(999);
    }

    public function testRefundsReportGroupsPixAndCardStatesAndFeedsTheNetReceivedTile(): void
    {
        $partial = $this->invoice(1, 20, '100', '0', 'Paid', '2026-09-10');
        $total = (new PaymentAttemptStore($this->pdo))->ensureCurrent(2, 21, 'pix', 5000, '2026-09-10')['id'];
        $review = (new PaymentAttemptStore($this->pdo))->ensureCurrent(3, 20, 'pix', 2000, '2026-09-10')['id'];
        $card = 'card-attempt';
        $this->pdo->exec("INSERT INTO pagou_payment_attempts (id, invoice_id, client_id, gateway, method, status, amount_cents, currency, idempotency_key, economic_key, created_at, updated_at, version) VALUES ('card-attempt', 4, 20, 'pagou_creditcard', 'card', 'paid', 9000, 'BRL', 'card-attempt-key', 'card-economic-key', '2026-09-10 12:00:00', '2026-09-10 12:00:00', 1)");
        $this->receipt('paid-1', 1, 20, 10000, 'pix', '2026-09-05 12:00:00', 'applied');
        $this->pixRefund($partial, 1, 20, 'charge-1', 4000, 10000, 'applied', 'refund-1', '2026-09-12 10:00:00');
        $this->pixRefund($total, 2, 21, 'charge-2', 5000, 5000, 'confirmed', 'refund-2', '2026-09-13 10:00:00');
        $this->pixRefund($review, 3, 20, 'charge-3', 2000, 2000, 'review', null, null, '2026-09-14 10:00:00');
        $this->pdo->prepare('INSERT INTO pagou_card_transactions (id, attempt_id, capture_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')->execute(['card-4', $card, 'captured', '2026-09-10 12:00:00', '2026-09-10 12:00:00']);
        $this->pdo->exec("INSERT INTO pagou_card_refunds (id,card_transaction_id,idempotency_key,amount_cents,status,requested_at_utc,created_at,updated_at) VALUES ('card-refund','card-4','card-key',9000,'dispatching','2026-09-15 10:00:00','2026-09-15 10:00:00','2026-09-15 10:00:00')");
        // Last month: outside the default period.
        $this->pixRefund((new PaymentAttemptStore($this->pdo))->ensureCurrent(5, 20, 'pix', 100, '2026-08-01')['id'], 5, 20, 'charge-5', 100, 100, 'applied', 'refund-5', '2026-08-20 10:00:00');

        $report = $this->model->report(['report' => 'refunds']);
        self::assertSame(4, $report['total']);
        self::assertSame([4, 3, 2, 1], array_column($report['rows'], 'invoice'));
        self::assertSame(['refund_progress', 'refund_review', 'refund_confirmed', 'refund_done'], array_column($report['rows'], 'status'));
        self::assertSame(['card', 'pix', 'pix', 'pix'], array_column($report['rows'], 'method'));
        self::assertSame([false, false, false, true], array_column($report['rows'], 'partial'));
        self::assertEquals(['refund_done' => 4000, 'refund_confirmed' => 5000, 'refund_review' => 2000, 'refund_progress' => 9000], $report['summary']['statusAmounts']);
        self::assertSame(1, $this->model->report(['report' => 'refunds', 'status' => 'refund_review'])['total']);
        self::assertSame(1, $this->model->report(['report' => 'refunds', 'method' => 'card'])['total']);
        self::assertSame(1, $this->model->report(['report' => 'refunds', 'from' => '2026-08-01', 'to' => '2026-08-31'])['total']);

        $html = (new MerchantReports())->report(['report' => $report], '<input type="hidden" name="token" value="x">');
        self::assertStringContainsString('Devolvido no período', $html);
        self::assertStringContainsString('R$ 90,00', $html);
        self::assertStringContainsString('Parcial<small>de R$ 100,00 recebidos</small>', $html);
        self::assertStringContainsString('Confirmada, registro pendente no WHMCS', $html);
        self::assertStringContainsString('Ainda não confirmada', $html);
        self::assertStringContainsString('Devoluções por situação', $html);
        $csv = (new ReportExport($this->pdo, ['report' => 'refunds', 'from' => '2026-09-01', 'to' => '2026-09-17'], $this->exportRequest()))->contents();
        self::assertStringContainsString('Devolução parcial do valor recebido', $csv);
        self::assertStringContainsString('Em conferência', $csv);

        // The month tile shows the net after refunds confirmed by Pagou this month.
        $overview = $this->model->overview();
        self::assertSame(9000, $overview['refunds']['statusAmounts']['refund_done'] + $overview['refunds']['statusAmounts']['refund_confirmed']);
        $dashboard = (new Dashboard())->render(['merchant' => $overview], '');
        self::assertStringContainsString('Líquido R$ 10,00 após R$ 90,00 em devoluções', $dashboard);
    }

    public function testDetailTimelineListsModuleEventsNewestFirstWithPixRefunds(): void
    {
        $attempt = $this->invoice(7, 20, '100', '0', 'Paid', '2026-09-10');
        $this->pdo->exec("UPDATE pagou_payment_attempts SET created_at = '2026-09-09 10:00:00' WHERE invoice_id = 7");
        $this->receipt('timeline', 7, 20, 10000, 'pix', '2026-09-10 12:00:00', 'applied');
        $this->pixRefund($attempt, 7, 20, 'charge-7', 2500, 10000, 'applied', 'refund-7', '2026-09-12 10:00:00', '2026-09-12 09:00:00');
        $this->pdo->exec("UPDATE pagou_pix_refunds SET updated_at = '2026-09-12 10:05:00'");
        $detail = $this->model->detail(7);
        self::assertSame(1, $detail['counts']['Devoluções Pix']);
        $html = (new MerchantReports())->detail(['detail' => $detail]);
        self::assertStringContainsString('Linha do tempo', $html);
        self::assertMatchesRegularExpression('#<span>ID do reembolso</span><code id="[^"]+" title="refund-7">refund-7</code>#', $html);
        $order = [];
        foreach (['Devolução registrada no WHMCS', 'Devolução Pix confirmada pela Pagou', 'Devolução Pix solicitada', 'Baixa registrada no WHMCS', 'Cobrança Pix criada'] as $title) {
            $position = strpos($html, '<strong>' . $title . '</strong>');
            self::assertIsInt($position, $title);
            $order[] = $position;
        }
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order);
        self::assertStringContainsString('R$ 25,00, parcial.', $html);
        // Technical tables remain available, collapsed below the timeline.
        self::assertStringContainsString('<details class="pagou-detail-records">', $html);
        self::assertStringContainsString('Devoluções Pix (1)', $html);
        self::assertStringContainsString('Registrada no WHMCS', $html);
        // The header summarises what matters: received and refunded, from applied records only.
        self::assertMatchesRegularExpression('#<dt>Recebido</dt><dd>R\$ 100,00</dd>#', $html);
        self::assertMatchesRegularExpression('#<dt>Devolvido</dt><dd>R\$ 25,00</dd><small>Devolução parcial</small>#', $html);
        self::assertStringContainsString('<li class="pagou-timeline-day"><span>', $html);
        // Attempts keep their anchors for links from the payments list, with copyable identifiers.
        self::assertStringContainsString('id="attempt-' . $attempt . '"', $html);
        self::assertStringContainsString('class="pagou-id-copy" data-pagou-copy=', $html);
        // The current charge appears once, with its own anchor; no "Atual" or "Anterior" tags.
        self::assertSame(1, substr_count($html, '<section class="pagou-card pagou-charge-panel pagou-charge-current" id="attempt-' . $attempt . '">'));
        self::assertSame(1, substr_count($html, '<span>ID Pagou</span>'));
        self::assertStringNotContainsString('pagou-charge-attempt-tag', $html);
    }

    public function testDetailListsTheOtherMethodChargeAsStillOpenApartFromPreviousAttempts(): void
    {
        $pix = $this->invoice(8, 20, '100', '0', 'Unpaid', '2026-09-10', 'pagou_boleto');
        $store = new PaymentAttemptStore($this->pdo);
        $store->complete($pix, 'pix-open', 'ready', []);
        $old = $store->ensureCurrent(8, 20, 'boleto', 9000, '2026-09-10')['id'];
        $store->complete($old, 'boleto-old', 'cancelled', []);
        $boleto = $store->ensureCurrent(8, 20, 'boleto', 10000, '2026-09-10')['id'];
        $store->complete($boleto, 'boleto-current', 'ready', []);
        $this->pdo->exec("UPDATE pagou_payment_attempts SET created_at = '2026-09-09 10:00:00' WHERE id = '" . $pix . "'");
        $this->pdo->exec("UPDATE pagou_payment_attempts SET created_at = '2026-09-09 09:00:00' WHERE id = '" . $old . "'");
        $this->pdo->exec("UPDATE pagou_payment_attempts SET created_at = '2026-09-09 08:00:00' WHERE id = '" . $boleto . "'");
        (new \Pagou\Whmcs\Application\Runtime\PaymentIdentityStore($this->pdo))->observeCharge($pix, 'pix', ['payer' => ['name' => 'Cliente', 'document' => '52998224725']]);
        (new \Pagou\Whmcs\Application\Runtime\PaymentIdentityStore($this->pdo))->observeCharge($boleto, 'boleto', ['payer' => ['name' => 'Cliente', 'document' => '52998224725']]);

        $html = (new MerchantReports())->detail(['detail' => $this->model->detail(8)]);

        // The invoice is on boleto: its boleto is the current charge, the Pix is still payable.
        self::assertStringContainsString('<section class="pagou-card pagou-charge-panel pagou-charge-current" id="attempt-' . $boleto . '">', $html);
        self::assertMatchesRegularExpression('#<h3>Também em aberto <span>1</span></h3>.*id="attempt-' . $pix . '".*<h3>Tentativas anteriores <span>1</span></h3>.*id="attempt-' . $old . '"#s', $html);
        self::assertStringContainsString('Continua valendo para pagamento até a fatura ser paga.', $html);
    }

    public function testDetailResolvesNotificationJobWithTheActualInboxDeduplicationKey(): void
    {
        $attempt = $this->invoice(1, 20, '100', '0', 'Unpaid', '2026-09-17');
        (new PaymentAttemptStore($this->pdo))->complete($attempt, 'charge-id', 'paid', []);
        $inbox = new \Pagou\Whmcs\Infrastructure\Persistence\Webhook\PdoWebhookInbox($this->pdo);
        $inbox->reserve('signed-delivery', new DateTimeImmutable('2026-09-17T12:00:00Z'));
        $inbox->markProcessed('signed-delivery');
        $this->pdo->exec("UPDATE pagou_webhook_deliveries SET provider_event_id = 'charge-id', event_type = 'qrcode.completed'");
        $key = hash('sha256', 'webhook:' . hash('sha256', 'signed-delivery'));
        $this->pdo->prepare('INSERT INTO pagou_payment_operations (id,operation_type,status,deduplication_key,priority,payload_json,available_at,created_at,updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['job', 'reconcile_payment', 'succeeded', $key, 100, '{}', '2026-09-17', '2026-09-17', '2026-09-17']);
        $detail = $this->model->detail(1);
        self::assertCount(1, $detail['notifications']);
        self::assertSame('succeeded', $detail['notifications'][0]['job_status']);
        self::assertArrayNotHasKey('payload_json', $detail['notifications'][0]);
        $this->pdo->exec('DELETE FROM pagou_payment_operations');
        self::assertSame('history_unavailable', $this->model->detail(1)['notifications'][0]['job_status']);
    }

    public function testUnknownMethodsStaySeparateAndZeroBaseHasNoInventedPercentage(): void
    {
        $this->receipt('unknown-method', 1, 20, 199, 'future-method', '2026-09-17 12:00:00', 'applied');
        $report = $this->model->report(['method' => 'unknown']);
        self::assertSame(199, $report['summary']['methods']['unknown']['amount']);
        $html = (new MerchantReports())->report(['report' => $report], '');
        self::assertStringContainsString('Sem base para comparação percentual', $html);
        self::assertStringNotContainsString('INF%', $html);
        self::assertStringContainsString('Não identificado', $html);
        self::assertSame('1', $this->model->report(['page' => '99'])['filter']['page']);
    }

    public function testCsvQuotesMultilineTextAndHtmlEscapesCustomerNames(): void
    {
        $name = "\t=FORMULA(\"x\"); <script>bad</script>\r\nsegunda linha";
        $this->pdo->prepare('UPDATE tblclients SET companyname = ? WHERE id = 20')->execute([$name]);
        $this->receipt('safe-id', 1, 20, 123, 'pix', '2026-09-17 12:00:00', 'applied');
        $csv = new ReportExport($this->pdo, ['from' => '2026-09-01', 'to' => '2026-09-17'], $this->exportRequest());
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $csv->contents());
        rewind($stream);
        fgetcsv($stream, null, ';', '"', '');
        $row = fgetcsv($stream, null, ';', '"', '');
        self::assertIsArray($row);
        self::assertCount(21, $row);
        self::assertSame("'" . trim($name), $row[3]);
        fclose($stream);
        $html = (new MerchantReports())->report(['report' => $this->model->report([])], '');
        self::assertStringNotContainsString('<script>bad</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testOverviewBuildsDailyTrendRecentReceiptsAndTheSameDaysOfLastMonth(): void
    {
        $this->pdo->prepare('UPDATE tblclients SET companyname = ? WHERE id = 20')->execute(['<script>bad</script> Ltda']);
        $this->invoice(1, 20, '100', '10', 'Unpaid', '2026-09-01');
        $this->invoice(2, 20, '50', '0', 'Unpaid', '2026-09-20');
        $this->receipt('today', 3, 20, 1234, 'pix', '2026-09-17 12:00:00', 'applied');
        // 02:30 UTC is still the previous day in São Paulo.
        $this->receipt('late-night', 4, 20, 2500, 'boleto', '2026-09-17 02:30:00', 'applied');
        $this->receipt('trend-start', 5, 20, 900, 'pix', '2026-08-20 12:00:00', 'applied');
        $this->receipt('last-month', 6, 20, 700, 'pix', '2026-08-10 12:00:00', 'applied');
        $this->receipt('quarantined', 7, 20, 9000, 'pix', '2026-09-17 12:00:00', 'quarantined');
        $this->finding('finding-1', null, []);

        $overview = $this->model->overview();

        self::assertCount(30, $overview['trend']);
        self::assertSame('2026-08-19', $overview['trend'][0]['date']);
        self::assertSame(['date' => '2026-08-20', 'amount' => 900, 'count' => 1], $overview['trend'][1]);
        self::assertSame(['date' => '2026-09-16', 'amount' => 2500, 'count' => 1], $overview['trend'][28]);
        self::assertSame(['date' => '2026-09-17', 'amount' => 1234, 'count' => 1], $overview['trend'][29]);
        self::assertSame(1234, $overview['recent'][0]['amount']);
        self::assertSame(700, $overview['lastMonth']['amount']);
        self::assertSame(['2026-08-01', '2026-08-17'], [$overview['lastMonth']['from'], $overview['lastMonth']['to']]);
        self::assertSame([1, 2], array_column($overview['openRows'], 'invoice'));

        $html = (new Dashboard())->render(['merchant' => $overview, 'findings' => 1, 'paymentsToday' => '1', 'paymentsTodayValue' => 'R$ 12,34'], '');
        self::assertSame(30, substr_count($html, 'data-count="'));
        self::assertStringContainsString('+433,4%', $html);
        self::assertStringContainsString('1 fatura(s) vencida(s)', $html);
        self::assertStringContainsString('16 dia(s) de atraso', $html);
        self::assertStringContainsString('1 pendência(s) financeira(s)', $html);
        self::assertStringContainsString('Ver os valores em tabela', $html);
        self::assertStringNotContainsString('<script>bad</script>', $html);
        self::assertStringContainsString('&lt;script&gt;bad&lt;/script&gt; Ltda', $html);
    }

    public function testLastMonthComparisonStopsAtTheEndOfAShorterMonth(): void
    {
        $model = new MerchantReadModel($this->pdo, new DateTimeImmutable('2026-03-31T15:00:00Z'));

        $overview = $model->overview();

        self::assertSame(['2026-02-01', '2026-02-28'], [$overview['lastMonth']['from'], $overview['lastMonth']['to']]);
    }

    public function testReadModelsAndExportDoNotWriteAnyFinancialOrOperationalTable(): void
    {
        $this->invoice(1, 20, '100', '10', 'Unpaid', '2026-09-16');
        $this->receipt('paid', 1, 20, 10000, 'pix', '2026-09-17 12:00:00', 'applied');
        $before = $this->pdo->query('SELECT total_changes()')->fetchColumn();
        $this->model->overview();
        $this->model->detail(1);
        foreach (['receipts', 'open', 'attempts', 'pending', 'refunds'] as $kind) {
            $this->model->report(['report' => $kind]);
            new ReportExport($this->pdo, ['report' => $kind], $this->exportRequest());
        }
        self::assertSame($before, $this->pdo->query('SELECT total_changes()')->fetchColumn());
    }

    private function exportRequest(): AdminRequest
    {
        return new AdminRequest([], ['action' => 'export-report', 'token' => 'test-csrf'], ['REQUEST_METHOD' => 'POST']);
    }

    private function invoice(int $id, int $client, string $total, string $credit, string $status, string $due, string $gateway = 'pagou_pix'): string
    {
        $this->pdo->prepare('INSERT INTO tblinvoices VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$id, $client, $total, $credit, $status, $due, $gateway]);
        return (new PaymentAttemptStore($this->pdo))->ensureCurrent($id, $client, 'pix', 10000, $due)['id'];
    }

    private function receipt(string $name, int $invoice, int $client, int $amount, string $method, string $paidAt, string $status): void
    {
        $key = hash('sha256', $name);
        $statement = $this->pdo->prepare('INSERT INTO pagou_ledger_entries (id,idempotency_key,invoice_id,client_id,entry_type,amount_cents,currency,payment_at_utc,effective_at_utc,metadata_json,created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$name, $key, $invoice, $client, 'received_payment', $amount, 'BRL', $paidAt, $paidAt, json_encode(['economic_key' => $key, 'method' => $method, 'remote_payment_id' => $name, 'remote_charge_id' => 'charge-' . $name, 'status' => 'received', 'secret' => 'SECRET-PAYLOAD']), $paidAt]);
        $statement->execute([$name . '-transition', hash('sha256', 'pagou-ledger-transition-v1|' . $key . '|' . $status), $invoice, $client, 'received_payment_transition', $amount, 'BRL', $paidAt, $paidAt, json_encode(['economic_key' => $key, 'status' => $status]), $paidAt]);
    }

    /** @param array<string,string> $details */
    private function finding(string $id, ?string $attempt, array $details, string $status = 'open'): void
    {
        $this->pdo->prepare('INSERT INTO pagou_reconciliation_findings (id,finding_key,attempt_id,severity,finding_type,status,details_json,detected_at_utc,created_at,updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, hash('sha256', $id), $attempt, 'high', 'payment_application_failed', $status, json_encode($details), '2026-09-15 12:00:00', '2026-09-15 12:00:00', '2026-09-15 12:00:00']);
    }

    private function pixRefund(string $attempt, int $invoice, int $client, string $remote, int $amount, int $receipt, string $status, ?string $provider, ?string $confirmed, string $requested = '2026-09-11 10:00:00'): void
    {
        $this->pdo->prepare('INSERT INTO pagou_pix_refunds (id, attempt_id, invoice_id, client_id, remote_id, original_transaction_id, amount_cents, receipt_cents, status, provider_refund_id, actor_id, requested_at, confirmed_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['pix-refund-' . $invoice, $attempt, $invoice, $client, $remote, 'tx-' . $invoice, $amount, $receipt, $status, $provider, 1, $requested, $confirmed, $requested]);
    }
}
