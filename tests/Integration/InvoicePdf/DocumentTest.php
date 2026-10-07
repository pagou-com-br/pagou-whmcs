<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\InvoicePdf;

use PDO;
use Pagou\Whmcs\Application\Runtime\PaymentAttemptStore;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Infrastructure\Whmcs\PrivateStorage;
use Pagou\Whmcs\InvoicePdf\{DocumentRenderer, DocumentSelector, PaymentDocument, PdfEngine, PixValidity};
use Pagou\Whmcs\Payment\Pix\Dto\{PixArtifacts, PixCharge};
use Pagou\Whmcs\Vendor\Fpdi\PdfParser\StreamReader;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    private PDO $pdo;
    private string $directory;
    private PaymentAttemptStore $attempts;
    private DocumentSelector $selector;
    /** @var array<string,mixed> */
    private array $invoice;
    private string $attemptId;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->directory = sys_get_temp_dir() . '/pagou-doc-' . bin2hex(random_bytes(6));
        $this->attempts = new PaymentAttemptStore($this->pdo);
        $this->invoice = ['result' => 'success', 'status' => 'Unpaid', 'userid' => 20, 'balance' => '10.00', 'paymentmethod' => 'pagou_pix', 'duedate' => '2099-09-30'];
        $this->selector = new DocumentSelector($this->pdo, new PrivateStorage($this->directory), function (string $command, array $input): array {
            self::assertSame('GetInvoice', $command);
            self::assertSame(['invoiceid' => 1], $input);
            return $this->invoice;
        });
        $this->attemptId = $this->attempts->ensureCurrent(1, 20, 'pix', 1000, '2099-09-30')['id'];
        $this->attempts->complete($this->attemptId, 'synthetic-pix', 'ready', ['copyPaste' => self::payload(), 'expiresAt' => '2099-09-30T23:59:59Z']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/boleto/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($this->directory . '/boleto')) {
            rmdir($this->directory . '/boleto');
        }
        rmdir($this->directory);
    }

    public static function payload(): string
    {
        $data = '00020101021226550014br.gov.bcb.pix2533example.invalid/pix/synthetic-test5204000053039865802BR5911TESTE PAGOU6009SAO PAULO62070503***6304';
        $crc = 0xffff;
        foreach (str_split($data) as $char) {
            $crc ^= ord($char) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = (($crc & 0x8000) ? ($crc << 1) ^ 0x1021 : $crc << 1) & 0xffff;
            }
        }
        return $data . sprintf('%04X', $crc);
    }

    public function testCurrentPixProducesACompleteSinglePageDocument(): void
    {
        $document = $this->selector->select(1, 'pagou_pix');
        self::assertNotNull($document);
        self::assertSame(self::payload(), $document->content);
        $pdf = (new DocumentRenderer())->render($document)->Output('', 'S');
        self::assertStringStartsWith('%PDF-', $pdf);
        $reader = new PdfEngine();
        self::assertSame(1, $reader->setSourceFile(StreamReader::createByString($pdf)));
        // The logo is the official raster, not an SVG redrawn by the WHMCS PDF engine.
        self::assertMatchesRegularExpression('#/Subtype\s*/Image#', $pdf);
    }

    public function testPaidChangedClientAmountGatewayAndDueDateNeverProducePaymentPdf(): void
    {
        $original = $this->invoice;
        foreach (['status' => 'Paid', 'userid' => 21, 'balance' => '9.00', 'paymentmethod' => 'pagoupix', 'duedate' => '2099-10-01'] as $key => $value) {
            $this->invoice = array_replace($original, [$key => $value]);
            self::assertNull($this->selector->select(1, 'pagou_pix'), $key);
        }
        $this->invoice = $original;
        self::assertNull($this->selector->select(1, 'pagoupix'));
        foreach (['paid', 'superseded', 'refunded', 'cancelled', 'queued'] as $status) {
            $statement = $this->pdo->prepare('UPDATE pagou_payment_attempts SET status = :status WHERE id = :id');
            $statement->execute(['status' => $status, 'id' => $this->attemptId]);
            self::assertNull($this->selector->select(1, 'pagou_pix'), $status);
        }
    }

    public function testDisabledPixEmailDetailsKeepTheNativeInvoicePdf(): void
    {
        $settings = new \Pagou\Whmcs\Configuration\CentralSettingsStore($this->pdo);
        self::assertNotNull($this->selector->select(1, 'pagou_pix'));
        $settings->save(['pix_email_details' => '0']);
        self::assertNull($this->selector->select(1, 'pagou_pix'));
        $settings->save(['pix_email_details' => '1']);
        self::assertNotNull($this->selector->select(1, 'pagou_pix'));
    }

    public function testExpiredMissingValidityOrDamagedPixCannotBeRendered(): void
    {
        foreach (['2000-01-01T00:00:00Z', '', 'invalid-date'] as $expiry) {
            $this->attempts->complete($this->attemptId, 'synthetic-pix', 'ready', ['copyPaste' => self::payload(), 'expiresAt' => $expiry]);
            self::assertNull($this->selector->select(1, 'pagou_pix'));
        }
        self::assertFalse(DocumentSelector::validPix(substr(self::payload(), 0, -4) . '0000'));
    }

    public function testDuePixUsesValiditySnapshotEvenWhenCreationHasNoStatusOrExpiresAt(): void
    {
        $charge = new PixCharge('synthetic-pix', 'unknown', 1000, new PixArtifacts(self::payload(), null, null), null, null, ['due_date' => '2099-09-30', 'expiration' => 3]);
        $validity = PixValidity::until($charge, '2099-09-01 00:00:00');
        self::assertSame('2099-10-03T23:59:59-03:00', $validity);
        $this->attempts->complete($this->attemptId, 'synthetic-pix', 'pending', ['copyPaste' => self::payload(), 'pdfValidUntil' => $validity]);
        self::assertNotNull($this->selector->select(1, 'pagou_pix'));
    }

    public function testBoletoPreservesEveryPageAndItsDimensions(): void
    {
        $source = new PdfEngine();
        $source->setPrintHeader(false);
        $source->setPrintFooter(false);
        $source->AddPage('P', 'A4');
        $source->SetFont('helvetica', '', 12);
        $source->Write(10, 'BOLETO SINTETICO - PAGINA 1');
        $source->AddPage('L', 'A5');
        $source->Write(10, 'BOLETO SINTETICO - PAGINA 2');
        $pdf = (new DocumentRenderer())->render(new PaymentDocument(1, 'boleto', 1000, $source->Output('', 'S')))->Output('', 'S');
        $reader = new PdfEngine();
        self::assertSame(2, $reader->setSourceFile(StreamReader::createByString($pdf)));
        $first = $reader->getTemplateSize($reader->importPage(1));
        $second = $reader->getTemplateSize($reader->importPage(2));
        self::assertEqualsWithDelta(210, $first['width'], 0.1);
        self::assertEqualsWithDelta(297, $first['height'], 0.1);
        self::assertEqualsWithDelta(210, $second['width'], 0.1);
        self::assertEqualsWithDelta(148, $second['height'], 0.1);
    }

    public function testBoletoSelectionRequiresTheCurrentLocalArtifactAndMatchingInvoice(): void
    {
        $this->invoice['paymentmethod'] = 'pagou_boleto';
        $id = $this->attempts->ensureCurrent(1, 20, 'boleto', 1000, '2099-09-30')['id'];
        // Make the old Pix unambiguously older even on second-resolution test clocks.
        $this->pdo->exec("UPDATE pagou_payment_attempts SET created_at='2000-01-01 00:00:00' WHERE method='pix'");
        $storage = new PrivateStorage($this->directory);
        $storage->put('boleto/current.pdf', '%PDF-synthetic');
        $update = $this->pdo->prepare("UPDATE pagou_payment_attempts SET status='ready',response_json=:json WHERE id=:id");
        $update->execute(['json' => json_encode(['artifacts' => ['local_pdf_key' => 'boleto/current.pdf']]), 'id' => $id]);
        self::assertSame('%PDF-synthetic', $this->selector->select(1, 'pagou_boleto')->content);
        $settings = new \Pagou\Whmcs\Configuration\CentralSettingsStore($this->pdo);
        foreach (['link', 'none'] as $mode) {
            $settings->save(['boleto_email_pdf_mode' => $mode]);
            self::assertNull($this->selector->select(1, 'pagou_boleto'));
        }
        $settings->save(['boleto_email_pdf_mode' => 'attach', 'boleto_email_details' => '0']);
        self::assertNull($this->selector->select(1, 'pagou_boleto'));
        $settings->save(['boleto_email_details' => '1']);
        self::assertNotNull($this->selector->select(1, 'pagou_boleto'));
        unlink($storage->path('boleto/current.pdf'));
        self::assertNull($this->selector->select(1, 'pagou_boleto'));
        $storage->put('boleto/current.pdf', '<html>Not a PDF</html>');
        self::assertNull($this->selector->select(1, 'pagou_boleto'));
    }

    public function testImmediateValidityUsesRecordedAttemptTimeAndNeverExtendsOnRead(): void
    {
        $charge = new PixCharge('synthetic', 'unknown', 1000, new PixArtifacts(self::payload(), null, null), null, null, ['expiration' => 3600]);
        self::assertSame('2026-09-17T13:00:00+00:00', PixValidity::until($charge, '2026-09-17 12:00:00'));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testBridgeReplacesOnlyACompletedEligibleDocumentAndKeepsOriginalOnFailure(): void
    {
        define('ROOTDIR', $this->directory . '/web');
        $oldStorage = getenv('PAGOU_PRIVATE_STORAGE_DIR');
        putenv('PAGOU_PRIVATE_STORAGE_DIR=' . $this->directory);
        $GLOBALS['pagou_whmcs_test_pdo'] = $this->pdo;
        $GLOBALS['pagou_whmcs_test_local_api'] = fn (): array => $this->invoice;
        try {
            $original = new PdfEngine();
            $pdf = $original;
            self::assertTrue(\Pagou\Whmcs\InvoicePdf\TemplateBridge::replace($pdf, 1, 'pagou_pix'));
            self::assertNotSame($original, $pdf);
            self::assertStringStartsWith('%PDF-', $pdf->Output('', 'S'));
            $this->invoice['status'] = 'Paid';
            $pdf = $original;
            self::assertFalse(\Pagou\Whmcs\InvoicePdf\TemplateBridge::replace($pdf, 1, 'pagou_pix'));
            self::assertSame($original, $pdf);
            $this->invoice['status'] = 'Unpaid';
            $this->attempts->complete($this->attemptId, 'synthetic-pix', 'ready', ['copyPaste' => 'invalid', 'expiresAt' => '2099-09-30T23:59:59Z']);
            self::assertFalse(\Pagou\Whmcs\InvoicePdf\TemplateBridge::replace($pdf, 1, 'pagou_pix'));
            self::assertSame($original, $pdf);
        } finally {
            unset($GLOBALS['pagou_whmcs_test_pdo'], $GLOBALS['pagou_whmcs_test_local_api']);
            putenv(is_string($oldStorage) ? 'PAGOU_PRIVATE_STORAGE_DIR=' . $oldStorage : 'PAGOU_PRIVATE_STORAGE_DIR');
        }
    }

    public function testInvalidBoletoFailsBeforeReplacingTheNativePdf(): void
    {
        $this->expectException(\Throwable::class);
        (new DocumentRenderer())->render(new PaymentDocument(1, 'boleto', 1000, '%PDF-invalid'));
    }
}
