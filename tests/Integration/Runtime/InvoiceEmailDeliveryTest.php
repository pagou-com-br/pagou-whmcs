<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use DateTimeImmutable;
use PDO;
use Pagou\Whmcs\Application\Async\{HandlerOutcome, JobPriority, OperationJob, OperationResult, OperationType};
use Pagou\Whmcs\Application\Runtime\{AddonSettings, PaymentAttemptStore, PixQrImage, PixQrLink, WhmcsRuntime};
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Whmcs\PrivateStorage;
use Pagou\Whmcs\Payment\Boleto\Domain\{BoletoArtifacts, BoletoAttempt};
use Pagou\Whmcs\Payment\Boleto\Infrastructure\PdoBoletoAttemptRepository;
use PHPUnit\Framework\TestCase;

final class InvoiceEmailDeliveryTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private PDO $pdo;
    private string $storage;
    private int $sends = 0;
    private bool $failSend = false;
    private mixed $oldStorage;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, total TEXT, credit TEXT, status TEXT, duedate TEXT, paymentmethod TEXT)');
        $this->pdo->exec('CREATE TABLE tblemailtemplates (name TEXT, type TEXT)');
        $this->pdo->exec("INSERT INTO tblemailtemplates VALUES ('Invoice Created','invoice'),('Invoice Payment Reminder','invoice'),('General Message','general')");
        $this->pdo->exec("INSERT INTO tblinvoices VALUES (1,20,'10.00','0','Unpaid','2026-09-30','pagou_boleto')");
        $this->oldStorage = getenv('PAGOU_PRIVATE_STORAGE_DIR');
        $this->storage = sys_get_temp_dir() . '/pagou-email-test-' . bin2hex(random_bytes(6));
        putenv('PAGOU_PRIVATE_STORAGE_DIR=' . $this->storage);
    }

    protected function tearDown(): void
    {
        @unlink($this->storage . '/boleto/test.pdf');
        @rmdir($this->storage . '/boleto');
        @rmdir($this->storage);
        putenv(is_string($this->oldStorage) ? 'PAGOU_PRIVATE_STORAGE_DIR=' . $this->oldStorage : 'PAGOU_PRIVATE_STORAGE_DIR');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testIntegratedThemeDoesNotAddASecondPdfAndDisabledNativeAttachmentKeepsExistingAttachment(): void
    {
        define('ROOTDIR', $this->storage . '/web');
        mkdir(ROOTDIR . '/templates/merchant', 0700, true);
        file_put_contents(ROOTDIR . '/templates/merchant/invoicepdf.tpl', "<?php\n// Merchant original template\n");
        $this->pdo->exec('CREATE TABLE tblconfiguration (setting TEXT,value TEXT)');
        $this->pdo->exec("INSERT INTO tblconfiguration VALUES ('Template','merchant'),('EnablePDFInvoices','on')");
        try {
            $integration = new \Pagou\Whmcs\InvoicePdf\IntegrationService($this->pdo, ROOTDIR);
            $integration->change('merchant', $integration->inspect()['hash'], true);
            $id = $this->attempt();
            $this->readyPdf($id);
            $runtime = $this->runtime();
            $fields = $runtime->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1]);
            self::assertArrayNotHasKey('attachments', $fields);
            self::assertArrayNotHasKey('abortsend', $fields);
            self::assertArrayHasKey('pagou_boleto_pdf_url', $fields);
            self::assertSame(0, $this->sends);
            $this->pdo->exec("UPDATE tblconfiguration SET value='' WHERE setting='EnablePDFInvoices'");
            self::assertArrayHasKey('attachments', $runtime->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1]));
            $this->pdo->exec("UPDATE tblconfiguration SET value='on' WHERE setting='EnablePDFInvoices'");
            $integration->change('merchant', $integration->inspect()['hash'], false);
            self::assertArrayHasKey('attachments', $runtime->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1]));
        } finally {
            foreach (['web', 'pdf-template-backups'] as $name) {
                $dir = $this->storage . '/' . $name;
                if (is_dir($dir)) {
                    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($iterator as $file) {
                        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                    }
                    rmdir($dir);
                }
            }
            @unlink($this->storage . '/pdf-template.lock');
        }
    }

    public function testInitialBoletoEmailWaitsForPdfWithoutIssuingSynchronously(): void
    {
        $runtime = $this->runtime();
        $pending = $runtime->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1]);
        self::assertTrue($pending['abortsend']);
        self::assertStringContainsString('Pagamento por boleto', $pending['invoice_payment_link']);
        self::assertSame('issue_boleto', $this->pdo->query('SELECT operation_type FROM pagou_payment_operations')->fetchColumn());
        self::assertSame(0, $this->sends);
        $attempt = (new PaymentAttemptStore($this->pdo))->latestForInvoice(1, 'boleto');
        self::assertSame('queued', $attempt['status']);
        $this->readyPdf((string) $attempt['id']);
        $fields = $runtime->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1]);
        self::assertSame('%PDF-1.4 synthetic', $fields['attachments'][0]['data']);
        self::assertArrayNotHasKey('abortsend', $fields);
    }

    public function testHeldInvoiceEmailHasADeadlineAndGoesOutWithoutTheBoletoWhenIssuanceFails(): void
    {
        $runtime = $this->runtime();
        self::assertTrue($runtime->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1])['abortsend']);
        self::assertTrue($runtime->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1])['abortsend']);
        // One deadline per invoice, thirty minutes after the email was held.
        $deadline = $this->pdo->query("SELECT payload_json, available_at, created_at FROM pagou_payment_operations WHERE operation_type = 'deliver_invoice_email'")->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $deadline);
        self::assertSame(['invoice_id' => 1, 'deadline' => true], json_decode($deadline[0]['payload_json'], true));
        self::assertSame(1800, strtotime($deadline[0]['available_at']) - strtotime($deadline[0]['created_at']));

        // The boleto was rejected: the invoice email still reaches the customer, once.
        $attempt = (string) (new PaymentAttemptStore($this->pdo))->latestForInvoice(1, 'boleto')['id'];
        (new PaymentAttemptStore($this->pdo))->markStatus($attempt, 'failed');
        self::assertSame(OperationResult::Succeeded, $this->deadline($runtime)->result);
        self::assertSame(1, $this->sends);
        self::assertSame(OperationResult::Succeeded, $this->deadline($runtime)->result);
        self::assertSame(1, $this->sends);
        self::assertSame('sent', $this->pdo->query('SELECT status FROM pagou_invoice_deliveries')->fetchColumn());
        // A later resend of the template is not held again.
        self::assertArrayNotHasKey('abortsend', $runtime->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1]));
    }

    public function testDeadlineSendIsNotHeldAgainAndCarriesTheBoletoWhenItBecameReady(): void
    {
        $runtime = $this->runtime();
        $id = $this->attempt();
        self::assertTrue($runtime->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1])['abortsend']);
        // While the deadline send is under way, its own Invoice Created is let through.
        $this->pdo->exec("INSERT INTO pagou_invoice_deliveries (delivery_key, invoice_id, attempt_id, status, attach_pdf, created_at, updated_at) VALUES ('k', 1, '" . $id . "', 'sending', 0, 'now', 'now')");
        self::assertArrayNotHasKey('abortsend', $runtime->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1]));
        $this->pdo->exec('DELETE FROM pagou_invoice_deliveries');

        $this->readyPdf($id);
        self::assertSame(OperationResult::Succeeded, $this->deadline($runtime)->result);
        self::assertSame(1, $this->sends);
        self::assertSame('1', (string) $this->pdo->query('SELECT attach_pdf FROM pagou_invoice_deliveries')->fetchColumn());
        // The regular delivery of the same boleto finds it sent and does not repeat it.
        self::assertSame(OperationResult::Succeeded, $this->deliver($runtime, $id)->result);
        self::assertSame(1, $this->sends);
    }

    public function testDeadlineDoesNothingAfterTheRegularDeliveryOrAPayment(): void
    {
        $runtime = $this->runtime();
        $id = $this->attempt();
        $this->readyPdf($id);
        self::assertSame(OperationResult::Succeeded, $this->deliver($runtime, $id)->result);
        self::assertSame(OperationResult::Succeeded, $this->deadline($runtime)->result);
        self::assertSame(1, $this->sends);

        $this->pdo->exec('DELETE FROM pagou_invoice_deliveries');
        $this->pdo->exec("UPDATE tblinvoices SET status='Paid'");
        self::assertSame(OperationResult::Succeeded, $this->deadline($runtime)->result);
        self::assertSame(1, $this->sends);
    }

    public function testOnlyUnpaidInvoiceEmailsReceivePaymentInstructions(): void
    {
        $runtime = $this->runtime();
        self::assertSame([], $runtime->emailPreSend(['messagename' => 'General Message', 'relid' => 1]));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pagou_payment_attempts')->fetchColumn());
        $id = $this->attempt();
        $this->readyPdf($id);
        self::assertArrayHasKey('attachments', $runtime->emailPreSend(['messagename' => 'Invoice Payment Reminder', 'relid' => 1]));
        $this->pdo->exec("UPDATE tblinvoices SET status='Paid'");
        self::assertSame(['invoice_payment_link' => ''], $runtime->emailPreSend(['messagename' => 'Invoice Payment Reminder', 'relid' => 1]));
        self::assertSame(OperationResult::Succeeded, $this->deliver($runtime, $id)->result);
        self::assertSame(0, $this->sends);
    }

    public function testSuccessfulEmailIsNotSentAgain(): void
    {
        $runtime = $this->runtime();
        $id = $this->attempt();
        $this->readyPdf($id);
        self::assertSame(OperationResult::Succeeded, $this->deliver($runtime, $id)->result);
        self::assertSame(OperationResult::Succeeded, $this->deliver($runtime, $id)->result);
        self::assertSame(1, $this->sends);
        self::assertSame('sent', $this->pdo->query('SELECT status FROM pagou_invoice_deliveries')->fetchColumn());
    }

    public function testUnknownAcceptanceStopsAutomaticResendAndCreatesFinding(): void
    {
        $runtime = $this->runtime();
        $id = $this->attempt();
        $this->failSend = true;
        self::assertSame(OperationResult::Uncertain, $this->deliver($runtime, $id)->result);
        $this->failSend = false;
        self::assertSame(OperationResult::Uncertain, $this->deliver($runtime, $id)->result);
        self::assertSame(1, $this->sends);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pagou_reconciliation_findings')->fetchColumn());
        self::assertSame('invoice_email_delivery_uncertain', $this->pdo->query('SELECT finding_type FROM pagou_reconciliation_findings')->fetchColumn());
    }

    public function testFailurePersistingSuccessfulSendStillCannotResend(): void
    {
        $runtime = $this->runtime();
        $id = $this->attempt();
        $this->pdo->exec("CREATE TRIGGER reject_sent BEFORE UPDATE ON pagou_invoice_deliveries WHEN NEW.status='sent' BEGIN SELECT RAISE(FAIL, 'synthetic local write failure'); END");
        self::assertSame(OperationResult::Uncertain, $this->deliver($runtime, $id)->result);
        $this->pdo->exec('DROP TRIGGER reject_sent');
        self::assertSame(OperationResult::Uncertain, $this->deliver($runtime, $id)->result);
        self::assertSame(1, $this->sends);
    }

    public function testSupersededAttemptAndChangedGatewayDoNotSendOldBoleto(): void
    {
        $runtime = $this->runtime();
        $id = $this->attempt();
        $this->readyPdf($id);
        (new PaymentAttemptStore($this->pdo))->markStatus($id, 'superseded');
        self::assertSame(['invoice_payment_link' => ''], $runtime->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1]));
        self::assertSame(OperationResult::Succeeded, $this->deliver($runtime, $id)->result);
        $this->pdo->exec("UPDATE pagou_payment_attempts SET status='ready'");
        $this->pdo->exec("UPDATE tblinvoices SET paymentmethod='mercadopago'");
        self::assertSame(OperationResult::Succeeded, $this->deliver($runtime, $id)->result);
        self::assertSame(0, $this->sends);
    }

    public function testLinkModeDoesNotDeferInitialEmailOrSendItAgain(): void
    {
        $runtime = $this->runtime(['boleto_email_pdf_mode' => 'link']);
        $fields = $runtime->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1]);
        self::assertArrayNotHasKey('abortsend', $fields);
        self::assertStringContainsString('Pagamento por boleto', $fields['invoice_payment_link']);
        $id = (string) (new PaymentAttemptStore($this->pdo))->latestForInvoice(1, 'boleto')['id'];
        self::assertSame(OperationResult::Succeeded, $this->deliver($runtime, $id)->result);
        self::assertSame(0, $this->sends);
    }

    public function testNonInvoiceDeliveryTemplateCreatesFindingWithoutSending(): void
    {
        $runtime = $this->runtime(['boleto_email_template' => 'General Message']);
        self::assertSame(OperationResult::PermanentFailure, $this->deliver($runtime, $this->attempt())->result);
        self::assertSame(0, $this->sends);
        self::assertSame('invoice_email_template_invalid', $this->pdo->query('SELECT finding_type FROM pagou_reconciliation_findings')->fetchColumn());
    }

    public function testEmailReplacesInteractiveGatewayMarkupWithoutChangingOtherGateways(): void
    {
        $id = $this->attempt();
        $this->readyPdf($id);
        $runtime = $this->runtime();
        $fields = $runtime->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1]);
        $html = $fields['invoice_payment_link'];
        foreach (['Pagamento por boleto', 'R$ 10,00', '30/09/2026', 'Linha digitável', 'Ver boleto', 'style=', 'line'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        foreach (['<input', '<button', '<script', '<svg', 'data:', 'automaticamente nesta página', 'class="pagou'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        // The Pagou boleto page opens without login, as the previous module's direct link did.
        self::assertStringContainsString('href="https://fatura.pagou.com.br/boleto/remote-test"', $html);
        self::assertStringContainsString('src="https://whmcs.test/modules/addons/pagou_payments/assets/email/barcode-white.png"', $html);
        self::assertSame('https://fatura.pagou.com.br/boleto/remote-test', $fields['pagou_boleto_url']);
        self::assertStringContainsString('href="https://whmcs.test/viewinvoice.php?id=1"', $html);
        self::assertStringNotContainsString('download.php', $html);
        self::assertSame('%PDF-1.4 synthetic', $fields['attachments'][0]['data']);
        self::assertSame('', $this->runtime(['boleto_email_details' => '0'])->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1])['invoice_payment_link']);
        $this->pdo->exec("UPDATE tblinvoices SET paymentmethod='edvan_boleto'");
        self::assertSame([], $runtime->emailPreSend(['messagename' => 'Invoice Created', 'relid' => 1]));
        self::assertSame(0, $this->sends);
    }

    public function testPixEmailNeverCarriesTheCodeOfACancelledOrReplacedCharge(): void
    {
        $this->pdo->exec("UPDATE tblinvoices SET paymentmethod='pagou_pix'");
        $store = new PaymentAttemptStore($this->pdo);
        $old = $store->ensureCurrent(1, 20, 'pix', 1000, '2026-09-30')['id'];
        $store->complete($old, 'charge-old', 'ready', ['state' => 'ready', 'copyPaste' => 'OLD-PIX-CODE', 'qrCodeImageUrl' => 'data:image/png;base64,T0xE']);
        $runtime = $this->runtime();
        $fields = $runtime->emailPreSend(['messagename' => 'Invoice Payment Reminder', 'relid' => 1]);
        self::assertSame('OLD-PIX-CODE', $fields['pagou_pix_copy_paste']);
        // A cancellation only updates the attempt; the stale projection must not be emailed.
        foreach (['cancel_requested', 'cancelled'] as $status) {
            $store->markStatus($old, $status);
            $fields = $runtime->emailPreSend(['messagename' => 'Invoice Payment Reminder', 'relid' => 1]);
            self::assertSame('', $fields['pagou_pix_copy_paste'], $status);
            self::assertSame('', $fields['pagou_pix_qr_code'], $status);
            self::assertSame('', $fields['invoice_payment_link'], $status);
        }
        self::assertSame(0, $this->sends);
    }

    public function testPixEmailShowsTheQrCodeFromASignedAddressOnlyWhilePayable(): void
    {
        $previous = $GLOBALS['cc_encryption_hash'] ?? null;
        $GLOBALS['cc_encryption_hash'] = str_repeat('h', 40);
        try {
            $this->pdo->exec("UPDATE tblinvoices SET paymentmethod='pagou_pix'");
            $store = new PaymentAttemptStore($this->pdo);
            $id = $store->ensureCurrent(1, 20, 'pix', 1000, '2026-09-30')['id'];
            $store->complete($id, 'charge-pix', 'ready', ['state' => 'ready', 'copyPaste' => 'PIX-CODE', 'qrCodeImageUrl' => 'data:image/png;base64,' . self::PNG,
                'pdfValidUntil' => gmdate('c', time() + 3600)]);

            $fields = $this->runtime()->emailPreSend(['messagename' => 'Invoice Payment Reminder', 'relid' => 1]);
            self::assertMatchesRegularExpression('#^https://whmcs\.test/modules/addons/pagou_payments/qr\.php\?a=' . $id . '&e=\d+&s=[a-f0-9]{32}$#', $fields['pagou_pix_qr_code']);
            self::assertStringContainsString('<img src="' . htmlspecialchars($fields['pagou_pix_qr_code'], ENT_QUOTES, 'UTF-8') . '"', $fields['invoice_payment_link']);
            self::assertStringNotContainsString('data:image', $fields['invoice_payment_link']);
            parse_str((string) parse_url($fields['pagou_pix_qr_code'], PHP_URL_QUERY), $query);
            self::assertTrue(PixQrLink::fromWhmcs()->valid($query['a'], $query['e'], $query['s'], time()));
            self::assertSame(['type' => 'image/png', 'data' => base64_decode(self::PNG)], (new PixQrImage($this->pdo))->find($id));

            // The merchant can hide the QR; the copy and paste code remains.
            $hidden = $this->runtime(['pix_show_qr' => '0'])->emailPreSend(['messagename' => 'Invoice Payment Reminder', 'relid' => 1]);
            self::assertSame('', $hidden['pagou_pix_qr_code']);
            self::assertStringNotContainsString('<img', $hidden['invoice_payment_link']);
            self::assertStringContainsString('PIX-CODE', $hidden['invoice_payment_link']);

            // An expired, paid or cancelled Pix, or a broken image, is never served.
            $image = new PixQrImage($this->pdo);
            self::assertNull($image->find($id, time() + 7200));
            $store->complete($id, 'charge-pix', 'ready', ['state' => 'ready', 'copyPaste' => 'PIX-CODE', 'qrCodeImageUrl' => 'data:image/png;base64,QUFBQQ==',
                'pdfValidUntil' => gmdate('c', time() + 3600)]);
            self::assertNull($image->find($id));
            $store->markStatus($id, 'cancelled');
            self::assertNull($image->find($id));
            self::assertNull($image->find($this->attempt()));
        } finally {
            if ($previous === null) {
                unset($GLOBALS['cc_encryption_hash']);
            } else {
                $GLOBALS['cc_encryption_hash'] = $previous;
            }
        }
    }

    public function testWithoutTheInstallationSecretThePixEmailKeepsTheCodeWithoutAnImage(): void
    {
        $previous = $GLOBALS['cc_encryption_hash'] ?? null;
        unset($GLOBALS['cc_encryption_hash']);
        try {
            $this->pdo->exec("UPDATE tblinvoices SET paymentmethod='pagou_pix'");
            $store = new PaymentAttemptStore($this->pdo);
            $id = $store->ensureCurrent(1, 20, 'pix', 1000, '2026-09-30')['id'];
            $store->complete($id, 'charge-pix', 'ready', ['state' => 'ready', 'copyPaste' => 'PIX-CODE', 'qrCodeImageUrl' => 'data:image/png;base64,' . self::PNG,
                'pdfValidUntil' => gmdate('c', time() + 3600)]);
            $fields = $this->runtime()->emailPreSend(['messagename' => 'Invoice Payment Reminder', 'relid' => 1]);
            self::assertSame('', $fields['pagou_pix_qr_code']);
            self::assertStringNotContainsString('<img', $fields['invoice_payment_link']);
            self::assertStringContainsString('PIX-CODE', $fields['invoice_payment_link']);
        } finally {
            if ($previous !== null) {
                $GLOBALS['cc_encryption_hash'] = $previous;
            }
        }
    }

    /** @param array<string,string> $settings */
    private function runtime(array $settings = []): WhmcsRuntime
    {
        return new WhmcsRuntime($this->pdo, new AddonSettings($settings), new PdoOperationOutbox($this->pdo), function (string $command, array $params): array {
            if ($command === 'GetInvoice') {
                return ['result' => 'success'] + $this->pdo->query('SELECT * FROM tblinvoices WHERE id=1')->fetch(PDO::FETCH_ASSOC);
            }
            if ($command === 'SendEmail') {
                $this->sends++;
                if ($this->failSend) {
                    throw new \RuntimeException('Synthetic failure after possible acceptance');
                }
            }
            return ['result' => 'success', 'value' => 'https://whmcs.test'];
        });
    }

    private function attempt(): string
    {
        return (new PaymentAttemptStore($this->pdo))->ensureCurrent(1, 20, 'boleto', 1000, '2026-09-30')['id'];
    }

    private function readyPdf(string $id): void
    {
        (new PrivateStorage($this->storage))->put('boleto/test.pdf', '%PDF-1.4 synthetic');
        $attempt = (new PaymentAttemptStore($this->pdo))->find($id);
        (new PdoBoletoAttemptRepository($this->pdo, 20))->save(new BoletoAttempt($id, '1', 1, 1000, '2026-09-30', (string) $attempt['idempotency_key'], BoletoAttempt::READY, 'remote-test', new BoletoArtifacts('line', 'barcode', 'pix-code', null, null, 'boleto/test.pdf')));
    }

    private function deadline(WhmcsRuntime $runtime): HandlerOutcome
    {
        $now = new DateTimeImmutable('now');
        $job = new OperationJob('deadline', OperationType::DeliverInvoiceEmail, 'invoice:delivery-deadline:1', JobPriority::Delivery, ['invoice_id' => 1, 'deadline' => true], $now, $now);
        return (new \ReflectionMethod($runtime, 'deliverInvoiceEmail'))->invoke($runtime, $job);
    }

    private function deliver(WhmcsRuntime $runtime, string $id): HandlerOutcome
    {
        $now = new DateTimeImmutable('now');
        $job = new OperationJob('job', OperationType::DeliverInvoiceEmail, 'delivery', JobPriority::Delivery, ['invoice_id' => 1, 'attempt_id' => $id, 'attach_pdf' => true], $now, $now);
        return (new \ReflectionMethod($runtime, 'deliverInvoiceEmail'))->invoke($runtime, $job);
    }
}
