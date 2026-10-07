<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Payments\Admin\View\SearchResults;
use Pagou\Whmcs\Application\Reporting\AdminSearch;
use Pagou\Whmcs\Application\Runtime\OperationalReadModel;
use Pagou\Whmcs\Application\Runtime\PaymentAttemptStore;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class AdminSearchTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($this->pdo))->migrate($migrations);
        $this->pdo->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT)');
        $this->pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, total TEXT, credit TEXT, status TEXT, duedate TEXT, paymentmethod TEXT)');
        $this->pdo->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, invoiceid INTEGER, transid TEXT, gateway TEXT, amountin TEXT, amountout TEXT)');
        $this->pdo->exec("INSERT INTO tblclients VALUES (20, 'Ana', 'Silva', ''), (21, 'Bruno', 'Costa', 'Costa_Hosting'), (22, 'Sem', 'Cobrança', '')");
        $this->pdo->exec("INSERT INTO tblinvoices VALUES (101, 20, '12.00', '0.00', 'Unpaid', '2026-09-20', 'pagou_pix'), (102, 21, '30.00', '0.00', 'Paid', '2026-09-10', 'pagou_boleto'), (103, 22, '5.00', '0.00', 'Unpaid', '2026-09-10', 'mercadopago')");
        $store = new PaymentAttemptStore($this->pdo);
        $pix = $store->ensureCurrent(101, 20, 'pix', 1200, '2026-09-20')['id'];
        $store->complete($pix, 'charge-abc123', 'ready', []);
        (new \Pagou\Whmcs\Application\Runtime\PaymentIdentityStore($this->pdo))->snapshot($pix, ['name' => 'Ana Silva', 'document' => '52998224725']);
        $boleto = $store->ensureCurrent(102, 21, 'boleto', 3000, '2026-09-10')['id'];
        $store->complete($boleto, 'boleto-xyz789', 'paid', []);
        $this->pdo->exec("INSERT INTO pagou_pix_payers VALUES ('payer-key', 'charge-abc123', 'receipt-555', 1200, 'Pagador', '11144477735', 'E12345678202609171200abcdefghijk', 0)");
        $this->pdo->exec("INSERT INTO tblaccounts VALUES (1, 102, 'RECEIPT-BOLETO-9', 'pagou_boleto', '30.00', '0.00'), (2, 103, 'other-gateway-1', 'mercadopago', '5.00', '0.00')");
    }

    public function testEachSupportedTermFindsTheInvoiceAndReportsWhichFieldMatched(): void
    {
        $search = new AdminSearch($this->pdo);
        $cases = [
            '#101' => [101, 'Número da fatura'],
            '529.982.247-25' => [101, 'CPF/CNPJ da emissão'],
            '11144477735' => [101, 'CPF/CNPJ de quem pagou'],
            'E12345678202609171200abcdefghijk' => [101, 'E2E do Pix'],
            'charge-abc123' => [101, 'ID Pagou da cobrança'],
            'receipt-555' => [101, 'ID do recebimento'],
            'receipt-boleto-9' => [102, 'Transação no WHMCS'],
            'bruno costa' => [102, 'Nome do cliente'],
        ];
        foreach ($cases as $term => [$invoice, $field]) {
            $term = (string) $term;
            $result = $search->search($term);
            self::assertNull($result['error'], $term);
            self::assertSame([$invoice], array_column($result['results'], 'invoice'), $term);
            self::assertContains($field, $result['results'][0]['matched'], $term);
        }
        $row = $search->search('101')['results'][0];
        self::assertSame(['customer' => 'Ana Silva', 'invoiceStatus' => 'Unpaid', 'method' => 'pix', 'amount' => 1200], array_intersect_key($row, array_flip(['customer', 'invoiceStatus', 'method', 'amount'])));
    }

    public function testSearchOnlyFindsModuleInvoicesAndTreatsWildcardsLiterally(): void
    {
        $search = new AdminSearch($this->pdo);
        self::assertSame([], $search->search('103')['results']);
        self::assertSame([], $search->search('other-gateway-1')['results']);
        self::assertSame([], $search->search('Sem Cobrança')['results']);
        self::assertSame([], $search->search('%%%')['results']);
        self::assertSame([102], array_column($search->search('Costa_H')['results'], 'invoice'));
        self::assertSame([], $search->search('Costa%H')['results']);
        self::assertSame('Informe um CPF ou CNPJ completo e válido.', $search->search('111.444.777-00')['error']);
        self::assertSame('Use até 64 caracteres na busca.', $search->search('!invalid')['error']);
        self::assertSame(['term' => '', 'results' => [], 'error' => null], $search->search('  '));
    }

    public function testResultsOpenTheInvoiceDirectlyOnlyForAnExactIdentifier(): void
    {
        $model = new OperationalReadModel($this->pdo);
        $exact = (new SearchResults())->render($model->admin('search', ['q' => 'charge-abc123']));
        self::assertStringContainsString('data-pagou-auto-open href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=101"', $exact);
        self::assertStringContainsString('Encontrada por: ID Pagou da cobrança', $exact);
        self::assertStringNotContainsString('529', $exact);
        $byName = (new SearchResults())->render($model->admin('search', ['q' => 'Ana']));
        self::assertStringNotContainsString('data-pagou-auto-open', $byName);
        self::assertStringContainsString('Fatura #101', $byName);
        $html = (new SearchResults())->render($model->admin('search', ['q' => '<script>']));
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('Nenhuma fatura com registros neste módulo', $html);
    }

    public function testSearchNeverWrites(): void
    {
        $before = $this->pdo->query('SELECT total_changes()')->fetchColumn();
        foreach (['101', 'Ana', '52998224725', 'charge-abc123', 'E12345678202609171200abcdefghijk'] as $term) {
            (new AdminSearch($this->pdo))->search($term);
        }
        self::assertSame($before, $this->pdo->query('SELECT total_changes()')->fetchColumn());
    }
}
