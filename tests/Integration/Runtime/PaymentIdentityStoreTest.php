<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\{PaymentIdentityStore, PaymentAttemptStore, RetentionService};
use Pagou\Whmcs\Application\Webhook\{HmacWebhookVerifier, WebhookEndpointService, WebhookEventParser};
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\Webhook\{OutboxWebhookEventHandler, PdoWebhookInbox};
use PHPUnit\Framework\TestCase;

final class PaymentIdentityStoreTest extends TestCase
{
    private PDO $pdo;
    private PaymentIdentityStore $store;
    private string $attempt;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $attempts = new PaymentAttemptStore($this->pdo);
        $this->attempt = $attempts->ensureCurrent(10, 20, 'pix', 1234, '2026-10-20')['id'];
        $attempts->complete($this->attempt, 'pix-test', 'ready', []);
        $this->store = new PaymentIdentityStore($this->pdo);
        $this->store->snapshot($this->attempt, ['name' => 'Emissão original', 'document' => '529.982.247-25']);
    }

    public function testVerifiedPayerSurvivesRetentionAndProfileChangesWithoutFinancialEffects(): void
    {
        $this->receive();
        $this->receive(offset: 1);
        $kept = $this->store->snapshot($this->attempt, ['name' => 'Novo cadastro', 'document' => '11144477735']);
        self::assertSame('Emissão original', $kept['name']);
        $this->pdo->exec("UPDATE pagou_webhook_deliveries SET received_at_utc = '2000-01-01', processing_status = 'succeeded'");
        (new RetentionService($this->pdo))->run();
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pagou_pix_payers')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pagou_ledger_entries')->fetchColumn());
        $row = $this->store->enrich([$this->record()])[0];
        self::assertSame('Quem pagou', $row['payerName']);
        self::assertSame('11144477735', $row['payerDocument']);
        self::assertSame('E123456', $row['e2e']);
        self::assertTrue($row['thirdParty']);
        self::assertStringNotContainsString('bank', json_encode($this->pdo->query('SELECT * FROM pagou_pix_payers')->fetchAll()));
    }

    public function testInvalidSignatureWrongChargeTransactionOwnerAndAmountCannotIdentifyPayer(): void
    {
        $this->receive(signed: false);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pagou_pix_payers')->fetchColumn());
        $this->receive();
        foreach ([['pagouId' => 'another'], ['reference' => 'another'], ['invoice' => 11], ['client' => 21], ['amount' => 999], ['method' => 'boleto']] as $change) {
            $row = $this->store->enrich([array_replace($this->record(), $change)])[0];
            self::assertSame('', $row['payerDocument']);
        }
        self::assertSame([], $this->store->forInvoice(11));
    }

    public function testConflictingReplayDoesNotOverwriteOrPresentAnUncertainPayer(): void
    {
        $this->receive();
        $this->receive(['payer' => ['name' => 'Outra pessoa', 'document' => '52998224725']], 1);
        self::assertSame('Quem pagou', $this->pdo->query('SELECT name FROM pagou_pix_payers')->fetchColumn());
        self::assertSame('', $this->store->enrich([$this->record()])[0]['payerDocument']);
    }

    public function testBoletoRequiresExplicitQrLinkAndSameReceiptIdentity(): void
    {
        $attempts = new PaymentAttemptStore($this->pdo);
        $id = $attempts->ensureCurrent(12, 20, 'boleto', 1234, '2026-10-20')['id'];
        $attempts->complete($id, 'boleto-test', 'ready', []);
        $row = array_replace($this->record(), ['invoice' => 12, 'method' => 'boleto', 'pagouId' => 'boleto-test']);
        $this->receive();
        self::assertSame('', $this->store->enrich([$row])[0]['payerDocument']);
        $this->store->observeCharge($id, 'boleto', ['payload' => ['qrcode_id' => 'pix-test']]);
        // A linkage discovered first must still allow the issuance snapshot later.
        $this->store->observeCharge($id, 'boleto', ['payer' => ['name' => 'Emitida', 'document' => '52998224725']]);
        self::assertSame('11144477735', $this->store->enrich([$row])[0]['payerDocument']);
        self::assertSame('Emitida', $this->store->enrich([$row])[0]['issuedName']);
        self::assertSame('', $this->store->enrich([array_replace($row, ['reference' => 'different'])])[0]['payerDocument']);
        $this->store->observeCharge($id, 'boleto', ['payload' => ['qrcode_id' => 'conflicting']]);
        self::assertSame('', $this->store->enrich([$row])[0]['payerDocument']);
    }

    public function testAnUnpaidInvoiceShowsOnlyItsCurrentChargeWithTheBoletoPix(): void
    {
        // A Pix issued first and cancelled when the invoice moved to boleto.
        $attempts = new PaymentAttemptStore($this->pdo);
        $pix = $attempts->ensureCurrent(51, 20, 'pix', 1412, '2026-10-22')['id'];
        $attempts->complete($pix, 'pix-cancelled', 'cancelled', []);
        $this->store->observeCharge($pix, 'pix', ['payer' => ['name' => 'Emitida', 'document' => '52998224725']]);
        $boleto = $attempts->ensureQueued(51, 20, 'boleto', 1412, 2, '2026-10-22')['id'];
        $attempts->complete($boleto, 'boleto-current', 'ready', []);
        $this->store->observeCharge($boleto, 'boleto', ['payer' => ['name' => 'Emitida', 'document' => '52998224725'], 'payload' => ['qrcode_id' => 'pix-in-boleto']]);
        $this->pdo->prepare('UPDATE pagou_payment_attempts SET created_at = ? WHERE id = ?')->execute(['2026-10-07 04:44:10', $pix]);
        $this->pdo->prepare('UPDATE pagou_payment_attempts SET created_at = ? WHERE id = ?')->execute(['2026-10-07 04:44:10', $boleto]);

        $rows = $this->store->forInvoice(51);
        self::assertCount(1, $rows);
        self::assertSame('boleto-current', $rows[0]['pagouId']);
        self::assertSame('pix-in-boleto', $rows[0]['pixId']);
        self::assertFalse($rows[0]['paid']);
    }

    public function testWithTwoOpenChargesTheInvoiceMethodDecidesWhichOneIsShown(): void
    {
        // The boleto from the e-mail stays open after the customer issued a Pix.
        $attempts = new PaymentAttemptStore($this->pdo);
        $boleto = $attempts->ensureCurrent(52, 20, 'boleto', 1412, '2026-10-22')['id'];
        $attempts->complete($boleto, 'boleto-open', 'ready', []);
        $this->store->observeCharge($boleto, 'boleto', ['payer' => ['name' => 'Emitida', 'document' => '52998224725'], 'payload' => ['qrcode_id' => 'pix-in-boleto']]);
        $pix = $attempts->ensureCurrent(52, 20, 'pix', 1412, '2026-10-22')['id'];
        $attempts->complete($pix, 'pix-open', 'ready', []);
        $this->store->observeCharge($pix, 'pix', ['payer' => ['name' => 'Emitida', 'document' => '52998224725']]);
        $this->pdo->prepare('UPDATE pagou_payment_attempts SET created_at = ? WHERE id = ?')->execute(['2026-10-07 04:40:00', $boleto]);
        $this->pdo->prepare('UPDATE pagou_payment_attempts SET created_at = ? WHERE id = ?')->execute(['2026-10-07 04:45:00', $pix]);

        self::assertSame('boleto-open', $this->store->forInvoice(52, 'boleto')[0]['pagouId']);
        self::assertSame('pix-in-boleto', $this->store->forInvoice(52, 'boleto')[0]['pixId']);
        self::assertSame('pix-open', $this->store->forInvoice(52, 'pix')[0]['pagouId']);
        // Without the invoice's method, the newest open charge.
        self::assertSame('pix-open', $this->store->forInvoice(52)[0]['pagouId']);
        self::assertCount(1, $this->store->forInvoice(52, 'boleto'));
    }

    public function testMissingPayerAndNonPixEventDoNotInventIdentification(): void
    {
        $this->receive(['payer' => ['name' => ['malformed'], 'document' => '123']]);
        $row = $this->store->enrich([$this->record()])[0];
        self::assertSame('', $row['payerDocument']);
        self::assertFalse($row['thirdParty']);
        $this->receive(['transaction_id' => 'not-pix'], 1, true, 'charge.paid');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pagou_pix_payers')->fetchColumn());
    }

    /** @return array<string,mixed> */
    private function record(): array
    {
        return ['invoice' => 10, 'client' => 20, 'method' => 'pix', 'amount' => 1234, 'pagouId' => 'pix-test', 'reference' => 'receipt-test'];
    }

    /** @param array<string,mixed> $changes */
    private function receive(array $changes = [], int $offset = 0, bool $signed = true, string $type = 'qrcode.completed'): void
    {
        $data = array_replace(['id' => 'pix-test', 'transaction_id' => 'receipt-test', 'amount' => '12.34', 'e2e_id' => 'E123456',
            'payer' => ['name' => 'Quem pagou', 'document' => '11144477735', 'bank' => ['account' => 'not-preserved']]], $changes);
        $body = json_encode(['name' => $type, 'data' => $data], JSON_THROW_ON_ERROR);
        $time = (string) (time() + $offset);
        $service = new WebhookEndpointService(new HmacWebhookVerifier('synthetic'), new WebhookEventParser(), new PdoWebhookInbox($this->pdo), new OutboxWebhookEventHandler($this->pdo, new PdoOperationOutbox($this->pdo)));
        $service->receive(['X-Pagou-Timestamp' => $time, 'X-Pagou-Signature' => $signed ? hash_hmac('sha256', $time . $body, 'synthetic') : str_repeat('0', 64)], $body);
    }
}
