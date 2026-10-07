<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use DateTimeImmutable;
use PDO;
use Pagou\Whmcs\Application\Runtime\{AddonSettings, ClientPaymentStatus, PaymentAttemptStore, WhmcsRuntime};
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class ChargeValidityAndPayerTest extends TestCase
{
    private PDO $pdo;
    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('America/Sao_Paulo');
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, total TEXT, credit TEXT, status TEXT, duedate TEXT, paymentmethod TEXT)');
        $this->pdo->exec("INSERT INTO tblinvoices VALUES (10, 20, '10.00', '0.00', 'Unpaid', '2026-09-11', 'pagou_pix')");
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    public function testAnImmediatePixExpiresOnlyAfterItsValidityAndADuePixNever(): void
    {
        $now = (new DateTimeImmutable('2026-10-07T12:00:00Z'))->getTimestamp();
        $ready = ['state' => 'ready', 'expiresAt' => '2026-10-07T11:58:00Z'];
        self::assertTrue(PaymentAttemptStore::pixExpired($ready, '', $now));
        // A short margin after the provider expiry avoids replacing a code being paid right now.
        self::assertFalse(PaymentAttemptStore::pixExpired(['state' => 'ready', 'expiresAt' => '2026-10-07T11:59:30Z'], '', $now));
        self::assertFalse(PaymentAttemptStore::pixExpired(['state' => 'ready', 'expiresAt' => '2026-11-06T12:00:00Z'], '', $now));
        self::assertFalse(PaymentAttemptStore::pixExpired($ready + ['dueDate' => '2026-10-01'], '', $now));
        self::assertFalse(PaymentAttemptStore::pixExpired(['state' => 'paid', 'expiresAt' => '2026-10-01T00:00:00Z'], '', $now));
        // Codes issued before the expiry was stored used the previous one-day validity.
        self::assertTrue(PaymentAttemptStore::pixExpired(['state' => 'ready'], '2026-10-06 11:00:00', $now));
        self::assertFalse(PaymentAttemptStore::pixExpired(['state' => 'ready'], '2026-10-06 13:00:00', $now));
    }

    public function testOpeningTheInvoiceReplacesAnExpiredPixAndTheClientNeverSeesTheOldCode(): void
    {
        $store = new PaymentAttemptStore($this->pdo);
        $old = $store->ensureCurrent(10, 20, 'pix', 1000, '2026-09-11');
        $store->complete($old['id'], 'pix-old', 'ready', ['state' => 'ready', 'copyPaste' => 'OLD-CODE', 'expiresAt' => '2026-01-01T00:00:00Z']);

        $status = (new ClientPaymentStatus($this->pdo))->read(10, 'pix', 20, false);
        self::assertSame('expired', $status['state']);
        self::assertStringNotContainsString('OLD-CODE', json_encode($status, JSON_THROW_ON_ERROR));

        $this->runtime()->scheduleInvoice(10);
        self::assertSame('superseded', $store->find($old['id'])['status']);
        $newest = $store->latestForInvoice(10, 'pix');
        self::assertNotSame($old['id'], $newest['id']);
        self::assertSame(2, (int) json_decode((string) $newest['request_json'], true)['revision']);
        // A valid code is kept: opening the invoice again creates nothing new.
        $store->complete($newest['id'], 'pix-new', 'ready', ['state' => 'ready', 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400)]);
        $this->runtime()->scheduleInvoice(10);
        self::assertSame($newest['id'], $store->latestForInvoice(10, 'pix')['id']);
        self::assertSame('ready', (new ClientPaymentStatus($this->pdo))->read(10, 'pix', 20, false)['state']);
    }

    public function testAnOverdueInvoiceIsChargedForTheNextDayAndCurrentDatesStay(): void
    {
        $morning = new DateTimeImmutable('2026-10-06T13:00:00Z');
        self::assertSame('2026-10-07', WhmcsRuntime::issuanceDueDate('2026-09-11', $morning));
        self::assertSame('2026-10-06', WhmcsRuntime::issuanceDueDate('2026-10-06', $morning));
        self::assertSame('2026-10-20', WhmcsRuntime::issuanceDueDate('2026-10-20', $morning));
        // At 22:00 in São Paulo the Pagou day (UTC) has already turned: the next local day is still valid.
        $night = new DateTimeImmutable('2026-10-07T01:00:00Z');
        self::assertSame('2026-10-07', WhmcsRuntime::issuanceDueDate('2026-10-05', $night));
        self::assertSame('invalid', WhmcsRuntime::issuanceDueDate('invalid', $morning));
    }

    public function testACnpjChargeCarriesTheCompanyNameAndACpfChargeThePerson(): void
    {
        $payer = new \ReflectionMethod(WhmcsRuntime::class, 'payer');
        $company = $payer->invoke($this->runtime(['companyname' => 'Empresa Exemplo Ltda', 'document' => '11.222.333/0001-81']), 20, false);
        self::assertSame(['name' => 'Empresa Exemplo Ltda', 'document' => '11222333000181'], $company);
        $person = $payer->invoke($this->runtime(['companyname' => 'Empresa Exemplo Ltda', 'document' => '529.982.247-25']), 20, false);
        self::assertSame('Cliente Teste', $person['name']);
        $withoutCompany = $payer->invoke($this->runtime(['companyname' => '', 'document' => '11222333000181']), 20, false);
        self::assertSame('Cliente Teste', $withoutCompany['name']);
    }

    /** @param array<string, string> $client */
    private function runtime(array $client = []): WhmcsRuntime
    {
        return new WhmcsRuntime($this->pdo, new AddonSettings(['cpf_field_id' => '5']), new PdoOperationOutbox($this->pdo), static function (string $command) use ($client): array {
            if ($command === 'GetClientsDetails') {
                return ['result' => 'success', 'firstname' => 'Cliente', 'lastname' => 'Teste', 'companyname' => $client['companyname'] ?? '',
                    'customfields' => ['customfield' => [['id' => 5, 'value' => $client['document'] ?? '']]]];
            }

            return ['result' => 'success', 'invoiceid' => 10, 'userid' => 20, 'paymentmethod' => 'pagou_pix', 'status' => 'Unpaid',
                'balance' => '10.00', 'total' => '10.00', 'duedate' => '2026-09-11', 'items' => ['item' => []]];
        });
    }
}
