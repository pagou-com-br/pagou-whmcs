<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\{AddonSettings, WhmcsRuntime};
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class InvoiceDocumentTest extends TestCase
{
    private PDO $pdo;
    private string $document = '';
    private string $country = 'BR';
    /** @var list<array<string, mixed>> */
    private array $updates = [];
    /** @var list<array<string, mixed>> */
    private array $emails = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->emails = [];
        $this->updates = [];
        $this->document = '';
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT, paymentmethod TEXT)');
        $this->pdo->exec('CREATE TABLE tblcustomfieldsvalues (fieldid INTEGER, relid INTEGER, value TEXT)');
    }

    public function testAPixRefusedForTheDocumentAsksForItOnTheInvoiceInsteadOfTryingAgain(): void
    {
        $runtime = $this->runtime('pix');
        self::assertFalse($runtime->scheduleInvoice(10));
        $html = $runtime->renderInvoice(['invoiceid' => 10, 'amount' => '29.99'], 'pix');

        self::assertStringContainsString('<strong>O Pix precisa de um CPF ou CNPJ válido.</strong><small>Informe o CPF ou CNPJ do titular para gerar o Pix agora, ou escolha outra forma de pagamento.</small>', $html);
        self::assertStringContainsString('data-pagou-document-form', $html);
        self::assertStringContainsString('<label for="pagou-document-10">CPF ou CNPJ do titular</label>', $html);
        self::assertMatchesRegularExpression('/name="token" value="[a-f0-9]{48}"/', $html);
        self::assertStringNotContainsString('Se uma nova cobrança', $html);
        // The same registration would be refused again: no new charge on each view.
        self::assertFalse($runtime->scheduleInvoice(10));
        self::assertSame(1, $this->attempts());
    }

    public function testOnlyTheInvoiceOwnerSavesAValidDocumentAndTheChargeIsIssuedAgain(): void
    {
        $runtime = $this->runtime('boleto');
        self::assertTrue($runtime->scheduleInvoice(10));
        $runtime->runWorker('test-worker');
        self::assertSame('boleto_local_validation:document_missing', $this->failure());
        // The invoice card in the admin says why, and what the customer can do.
        self::assertSame(
            'CPF ou CNPJ não encontrado no cadastro do cliente, nos campos configurados. O cliente pode informar o documento na própria fatura ou no cadastro, e a cobrança é gerada de novo.',
            $runtime->adminSummary(10)['failure'],
        );

        self::assertSame('forbidden', $runtime->saveInvoiceDocument(10, 'boleto', 99, '529.982.247-25')['status']);
        self::assertSame('forbidden', $runtime->saveInvoiceDocument(10, 'pix', 20, '529.982.247-25')['status']);
        self::assertSame('invalid', $runtime->saveInvoiceDocument(10, 'boleto', 20, '123.456.789-01')['status']);
        self::assertSame([], $this->updates);

        self::assertSame(['status' => 'ok'], $runtime->saveInvoiceDocument(10, 'boleto', 20, '52998224725'));
        // Saved formatted in the configured CPF field, through the native update.
        self::assertSame([['clientid' => 20, 'customfields' => [5 => '529.982.247-25']]], $this->updates);
        self::assertSame(['failed', 'queued'], $this->pdo->query('SELECT status FROM pagou_payment_attempts ORDER BY created_at, status')->fetchAll(PDO::FETCH_COLUMN));
        // A charge issued again no longer offers the form.
        self::assertSame('forbidden', $runtime->saveInvoiceDocument(10, 'boleto', 20, '529.982.247-25')['status']);
    }

    public function testACnpjGoesToTheCnpjFieldOrToTheOnlyFieldConfigured(): void
    {
        $runtime = $this->runtime('boleto', ['cpf_field_id' => '5', 'cnpj_field_id' => '6']);
        $runtime->scheduleInvoice(10);
        $runtime->runWorker('test-worker');
        self::assertSame('ok', $runtime->saveInvoiceDocument(10, 'boleto', 20, '11222333000181')['status']);

        self::assertSame([6 => '11.222.333/0001-81'], $this->updates[0]['customfields']);
    }

    public function testAProfileCorrectedOutsideTheInvoiceIssuesTheRefusedChargeAgain(): void
    {
        $runtime = $this->runtime('boleto', ['cpf_field_id' => '5', 'notify_refused_charge' => '1']);
        $runtime->scheduleInvoice(10);
        $runtime->runWorker('test-worker');
        self::assertSame('boleto_local_validation:document_missing', $this->failure());
        self::assertCount(1, $this->refusalNotices());

        // Saved unchanged, saved with the module still refusing it, or another client: nothing is tried.
        self::assertSame(0, $runtime->retryRefusedCharges(20));
        $this->country = 'PT';
        self::assertSame(0, $runtime->retryRefusedCharges(20));
        self::assertSame(0, $runtime->retryRefusedCharges(99));
        self::assertSame(1, $this->attempts());
        self::assertCount(1, $this->refusalNotices());

        $this->country = 'BR';
        $this->document = '529.982.247-25';
        self::assertSame(1, $runtime->retryRefusedCharges(20));
        self::assertSame(['failed', 'queued'], $this->pdo->query('SELECT status FROM pagou_payment_attempts ORDER BY created_at, status')->fetchAll(PDO::FETCH_COLUMN));
        // Already prepared again: a second save changes nothing.
        self::assertSame(0, $runtime->retryRefusedCharges(20));
        self::assertSame(2, $this->attempts());
    }

    public function testTheFormIsOfferedOnlyForADocumentRefusalWithAConfiguredField(): void
    {
        // Refused for the address, not the document.
        $this->country = 'PT';
        $this->document = '529.982.247-25';
        $runtime = $this->runtime('boleto');
        $runtime->scheduleInvoice(10);
        $runtime->runWorker('test-worker');
        self::assertStringNotContainsString('data-pagou-document-form', $runtime->renderInvoice(['invoiceid' => 10, 'amount' => '29.99'], 'boleto'));
        self::assertSame('forbidden', $runtime->saveInvoiceDocument(10, 'boleto', 20, '529.982.247-25')['status']);

        // Without a document field the module cannot know where to save it.
        $this->setUp();
        $runtime = $this->runtime('pix', []);
        $runtime->scheduleInvoice(10);
        $html = $runtime->renderInvoice(['invoiceid' => 10, 'amount' => '29.99'], 'pix');
        self::assertStringContainsString('O Pix precisa de um CPF ou CNPJ válido.', $html);
        self::assertStringNotContainsString('data-pagou-document-form', $html);
    }

    public function testRepeatedTriesAreLimitedPerInvoice(): void
    {
        $runtime = $this->runtime('pix');
        $runtime->scheduleInvoice(10);
        foreach (range(1, 5) as $try) {
            self::assertSame('invalid', $runtime->saveInvoiceDocument(10, 'pix', 20, '111.111.111-11')['status'], (string) $try);
        }

        self::assertSame('limited', $runtime->saveInvoiceDocument(10, 'pix', 20, '529.982.247-25')['status']);
        self::assertSame([], $this->updates);
    }

    public function testAChargeNotIssuedIsListedUntilTheInvoiceMovesOnAndTheCustomerIsToldOnceWhenEnabled(): void
    {
        $runtime = $this->runtime('boleto');
        $runtime->scheduleInvoice(10);
        $runtime->runWorker('test-worker');
        $finding = $this->pdo->query("SELECT status, severity, details_json FROM pagou_reconciliation_findings WHERE finding_type = 'charge_issuance_refused'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['open', 'medium'], [$finding['status'], $finding['severity']]);
        self::assertStringStartsWith('CPF ou CNPJ não encontrado no cadastro do cliente', json_decode((string) $finding['details_json'], true)['message']);
        // Off by default: the module sends nothing to customers unless the merchant enables it.
        self::assertSame([], $this->emails);

        // Issued again with the corrected document: the listing closes by itself.
        $runtime->saveInvoiceDocument(10, 'boleto', 20, '529.982.247-25');
        self::assertSame(1, $this->housekeeping()->closeRefusals(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        self::assertSame('resolved', $this->pdo->query("SELECT status FROM pagou_reconciliation_findings WHERE finding_type = 'charge_issuance_refused'")->fetchColumn());

        $this->setUp();
        $runtime = $this->runtime('pix', ['cpf_field_id' => '5', 'notify_refused_charge' => '1']);
        $runtime->scheduleInvoice(10);
        $runtime->scheduleInvoice(10);
        self::assertCount(1, $this->emails);
        self::assertSame(['invoice', 10], [$this->emails[0]['customtype'], $this->emails[0]['id']]);
        self::assertSame('Fatura #{$invoice_num}: não foi possível gerar o Pix', $this->emails[0]['customsubject']);
        self::assertStringContainsString('O Pix precisa de um CPF ou CNPJ válido. Abra a fatura e informe o documento do titular para gerar o Pix na hora', $this->emails[0]['custommessage']);
        // A button to the invoice itself: {$invoice_link} already renders as a link.
        self::assertStringContainsString('<a href="https://cliente.example.com/viewinvoice.php?id=10" style="display:inline-block;background:#2856d9;', $this->emails[0]['custommessage']);
        self::assertStringContainsString('>Abrir a fatura</a>', $this->emails[0]['custommessage']);
        self::assertStringNotContainsString('invoice_link', $this->emails[0]['custommessage']);
        self::assertSame('sent', $this->pdo->query('SELECT status FROM pagou_customer_notices')->fetchColumn());

        // Still unpaid and refused: listed. Another method or a paid invoice: closed.
        $housekeeping = $this->housekeeping();
        self::assertSame(0, $housekeeping->closeRefusals(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        $this->pdo->exec("UPDATE tblinvoices SET paymentmethod = 'paypal'");
        self::assertSame(1, $housekeeping->closeRefusals(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
    }

    public function testADraftReachesPagouOnlyOnceItIsPublished(): void
    {
        $runtime = $this->runtime('pix', ['cpf_field_id' => '5', 'notify_refused_charge' => '1']);
        $this->pdo->exec("UPDATE tblinvoices SET status = 'Draft'");
        // A draft still changes and the customer cannot see it: no charge, no notice, nothing listed.
        self::assertFalse($runtime->scheduleInvoice(10));
        self::assertSame(0, $this->attempts());
        self::assertSame([], $this->emails);
        self::assertSame('draft', $runtime->adminSummary(10)['state']);

        $this->pdo->exec("UPDATE tblinvoices SET status = 'Unpaid'");
        $runtime->scheduleInvoice(10);
        $runtime->scheduleInvoice(10);
        self::assertCount(1, $this->emails);
        self::assertSame(1, $this->attempts());
        // A listing closed while the same refusal stands comes back, without a second notice.
        $this->pdo->exec("UPDATE pagou_reconciliation_findings SET status = 'resolved'");
        $runtime->scheduleInvoice(10);
        self::assertSame('open', $this->pdo->query("SELECT status FROM pagou_reconciliation_findings WHERE finding_type = 'charge_issuance_refused'")->fetchColumn());
        self::assertCount(1, $this->emails);
    }

    private function housekeeping(): \Pagou\Whmcs\Application\Runtime\OperationHousekeeping
    {
        return new \Pagou\Whmcs\Application\Runtime\OperationHousekeeping($this->pdo, new \Pagou\Whmcs\Application\Async\OperationScheduler(new PdoOperationOutbox($this->pdo), new \Pagou\Whmcs\Application\Async\SystemAsyncClock()));
    }

    /** @param array<string, string> $settings */
    private function runtime(string $method, array $settings = ['cpf_field_id' => '5']): WhmcsRuntime
    {
        $this->pdo->prepare('INSERT INTO tblinvoices (id, userid, status, paymentmethod) VALUES (10, 20, ?, ?)')->execute(['Unpaid', 'pagou_' . $method]);

        return new WhmcsRuntime($this->pdo, new AddonSettings($settings), new PdoOperationOutbox($this->pdo), function (string $command, array $parameters) use ($method): array {
            if ($command === 'GetConfigurationValue') {
                return ['result' => 'success', 'value' => 'https://cliente.example.com/'];
            }
            if ($command === 'SendEmail') {
                $this->emails[] = $parameters;

                return ['result' => 'success'];
            }
            if ($command === 'UpdateClient') {
                $parameters['customfields'] = unserialize(base64_decode((string) $parameters['customfields']));
                $this->updates[] = $parameters;
                $this->document = (string) current($parameters['customfields']);

                return ['result' => 'success'];
            }
            if ($command === 'GetClientsDetails') {
                return ['result' => 'success', 'firstname' => 'Cliente', 'lastname' => 'Teste', 'email' => 'cliente@example.com', 'phonenumber' => '11999999999',
                    'address1' => 'Rua A, 1', 'city' => 'São Paulo', 'state' => 'SP', 'postcode' => $this->country === 'BR' ? '01310-100' : '4805-259',
                    'country' => $this->country, 'customfields' => ['customfield' => [['id' => 5, 'value' => $this->document], ['id' => 6, 'value' => '']]]];
            }

            return ['result' => 'success', 'invoiceid' => 10, 'userid' => 20, 'paymentmethod' => 'pagou_' . $method,
                'status' => (string) $this->pdo->query('SELECT status FROM tblinvoices WHERE id = 10')->fetchColumn(),
                'balance' => '29.99', 'total' => '29.99', 'duedate' => '2030-01-15', 'items' => ['item' => []]];
        });
    }

    /** @return list<array<string, mixed>> */
    private function refusalNotices(): array
    {
        return array_values(array_filter($this->emails, static fn (array $email): bool => str_contains((string) ($email['customsubject'] ?? ''), 'não foi possível gerar')));
    }

    private function attempts(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM pagou_payment_attempts')->fetchColumn();
    }

    private function failure(): string
    {
        return (string) (json_decode((string) $this->pdo->query('SELECT response_json FROM pagou_payment_attempts ORDER BY created_at DESC LIMIT 1')->fetchColumn(), true)['failure'] ?? '');
    }
}
