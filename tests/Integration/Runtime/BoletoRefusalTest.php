<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use DateTimeImmutable;
use PDO;
use Pagou\Whmcs\Application\Async\{OperationScheduler, SystemAsyncClock};
use Pagou\Whmcs\Application\Runtime\{AddonSettings, OperationHousekeeping, PaymentAttemptStore, WhmcsRuntime};
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class BoletoRefusalTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string, string> */
    private array $client = [];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, status TEXT)');
        $this->pdo->exec("INSERT INTO tblinvoices (id, status) VALUES (10, 'Unpaid')");
        $this->client = ['firstname' => 'Cliente', 'lastname' => 'Exterior', 'email' => 'cliente@example.com', 'phonenumber' => '+351.900000000',
            'address1' => 'Rua da Fonte, 315', 'city' => 'Ponte', 'state' => 'Guimarães', 'postcode' => '4805-259', 'country' => 'PT'];
    }

    public function testAClientAbroadSeesTheBoletoRefusedAndItIsIssuedAgainOnlyOnceTheAddressChanges(): void
    {
        $runtime = $this->runtime();
        self::assertTrue($runtime->scheduleInvoice(10));
        $report = $runtime->runWorker('test-worker');

        // Refused before any request to Pagou: the address cannot carry a boleto.
        self::assertSame(1, $report->failed);
        self::assertSame('boleto_local_validation:foreign_address', $this->pdo->query('SELECT error_message FROM pagou_payment_operations')->fetchColumn());
        $attempt = $this->pdo->query('SELECT status, remote_id, response_json FROM pagou_payment_attempts')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('failed', $attempt['status']);
        self::assertNull($attempt['remote_id']);
        self::assertSame('boleto_local_validation:foreign_address', json_decode((string) $attempt['response_json'], true)['failure']);
        $html = $runtime->renderInvoice(['invoiceid' => 10, 'amount' => '479.90'], 'boleto');
        self::assertStringContainsString('Falha na emissão', $html);
        // What happened stands out; what to do follows in smaller text, as in the loading notice.
        self::assertStringContainsString('<strong>O boleto exige um endereço no Brasil.</strong><small>Escolha outra forma de pagamento ou atualize o endereço no seu cadastro.</small>', $html);
        self::assertStringNotContainsString('Preparando seu boleto', $html);

        // Each view of the invoice keeps the refusal instead of issuing it again.
        self::assertFalse($runtime->scheduleInvoice(10));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pagou_payment_attempts')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pagou_payment_operations')->fetchColumn());

        // A corrected registration is issued again by itself.
        $this->client = ['state' => 'SP', 'postcode' => '01310-100', 'country' => 'BR', 'city' => 'São Paulo'] + $this->client;
        self::assertTrue($runtime->scheduleInvoice(10));
        self::assertSame(['failed', 'queued'], $this->pdo->query('SELECT status FROM pagou_payment_attempts ORDER BY created_at, status')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testABrazilianCepIsIssuedEvenWithTheCountryLeftWrong(): void
    {
        $this->client = ['state' => 'SP', 'postcode' => '01310-100', 'country' => 'US'] + $this->client;
        $payer = (new \ReflectionMethod(WhmcsRuntime::class, 'payer'))->invoke($this->runtime(), 20, true);

        self::assertSame('01310100', $payer['zip']);
    }

    public function testTheBoletoPayerIsSentAsTheBankRegistersIt(): void
    {
        $this->client = ['firstname' => 'Ana &amp; Filhos', 'lastname' => 'Comércio', 'address1' => "Rua A \u{2013} 15", 'state' => 'SP', 'postcode' => '01310-100', 'country' => 'BR'] + $this->client;
        $payer = (new \ReflectionMethod(WhmcsRuntime::class, 'payer'))->invoke($this->runtime(), 20, true);

        self::assertSame('Ana e Filhos Comércio', $payer['name']);
        self::assertSame('Rua A - 15', $payer['street']);
        // Pix receives the name itself, without the entity: only the bank file is limited to Latin-1.
        self::assertSame('Ana & Filhos Comércio', (new \ReflectionMethod(WhmcsRuntime::class, 'payer'))->invoke($this->runtime(), 20, false)['name']);
    }

    public function testHousekeepingShowsAsFailedTheBoletosRefusedBeforeThisRelease(): void
    {
        $store = new PaymentAttemptStore($this->pdo);
        $attempts = [];
        foreach ([11 => 'boleto_request_rejected:unknown', 12 => 'boleto_local_validation:missing_zip', 13 => 'boleto_local_validation:unknown', 14 => 'boleto_request_rejected:invalid_customer_address'] as $invoice => $reason) {
            $attempts[$invoice] = $store->ensureCurrent($invoice, 20, 'boleto', 47990, '2030-01-15')['id'];
            $this->operation('issue-' . $invoice, $attempts[$invoice], 'failed', $reason);
        }
        // Still being worked on: left to the queue.
        $this->operation('lookup-14', $attempts[14], 'retrying', null);

        $housekeeping = new OperationHousekeeping($this->pdo, new OperationScheduler(new PdoOperationOutbox($this->pdo), new SystemAsyncClock()));
        self::assertSame(2, $housekeeping->refuse(new DateTimeImmutable('now', new \DateTimeZone('UTC'))));

        foreach ([11 => 'failed', 12 => 'failed', 13 => 'queued', 14 => 'queued'] as $invoice => $status) {
            self::assertSame($status, $store->find($attempts[$invoice])['status'], (string) $invoice);
        }
        // Two charges never issued: neither carries an empty identity that would collide.
        self::assertNull($store->find($attempts[11])['remote_id']);
        self::assertNull($store->find($attempts[12])['remote_id']);
        self::assertStringContainsString('Confira o CPF ou CNPJ e o endereço', (string) ($store->currentDisplayForInvoice(11, 'boleto')['message'] ?? ''));
        // Without a payer fingerprint the next view tries once more with the current data.
        self::assertArrayNotHasKey('payer', json_decode((string) $store->find($attempts[11])['response_json'], true));
        self::assertSame(0, $housekeeping->refuse(new DateTimeImmutable('now', new \DateTimeZone('UTC'))));
    }

    private function runtime(): WhmcsRuntime
    {
        return new WhmcsRuntime($this->pdo, new AddonSettings(['cpf_field_id' => '5']), new PdoOperationOutbox($this->pdo), function (string $command): array {
            if ($command === 'GetClientsDetails') {
                return ['result' => 'success', 'companyname' => '', 'customfields' => ['customfield' => [['id' => 5, 'value' => '529.982.247-25']]]] + $this->client;
            }

            return ['result' => 'success', 'invoiceid' => 10, 'userid' => 20, 'paymentmethod' => 'pagou_boleto', 'status' => 'Unpaid',
                'balance' => '479.90', 'total' => '479.90', 'duedate' => '2030-01-15', 'items' => ['item' => []]];
        });
    }

    private function operation(string $id, string $attempt, string $status, ?string $error): void
    {
        $at = (new DateTimeImmutable('-30 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->pdo->prepare('INSERT INTO pagou_payment_operations (id,operation_type,status,deduplication_key,priority,payload_json,available_at,created_at,updated_at,attempt_id,error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $error === null ? 'reconcile_uncertain_operation' : 'issue_boleto', $status, hash('sha256', $id), 50, json_encode(['attempt_id' => $attempt]), $at, $at, $at, $attempt, $error]);
    }
}
