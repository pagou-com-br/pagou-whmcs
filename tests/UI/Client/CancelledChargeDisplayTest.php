<?php

declare(strict_types=1);

namespace Tests\UI\Client;

use PDO;
use Pagou\Whmcs\Application\Runtime\AddonSettings;
use Pagou\Whmcs\Application\Runtime\ClientPaymentStatus;
use Pagou\Whmcs\Application\Runtime\PaymentAttemptStore;
use Pagou\Whmcs\Application\Runtime\WhmcsRuntime;
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class CancelledChargeDisplayTest extends TestCase
{
    private const QR = 'data:image/png;base64,iVBORw0KGgo=';

    public function testInvoiceCancellationHidesThePixDataThroughoutTheCancellationFlow(): void
    {
        [$pdo, $store] = $this->fixture();
        $attempt = $store->ensureCurrent(109, 7, 'pix', 1200, '2026-10-10');
        $store->complete($attempt['id'], 'remote-109', 'pending', [
            'state' => 'pending', 'amount' => '12,00', 'copyPaste' => 'pix-code-109', 'qrCodeImageUrl' => self::QR,
            'expiresAt' => '2026-10-11T12:00:00Z',
        ]);
        self::assertStringContainsString('pix-code-109', $this->render($pdo, 'pix'));

        // The InvoiceCancelled hook only records the request and schedules the remote cancellation.
        $pdo->exec("UPDATE tblinvoices SET status = 'Cancelled'");
        self::assertFalse($this->runtime($pdo, 'Cancelled')->scheduleInvoice(109));
        self::assertSame('cancel_requested', $store->find($attempt['id'])['status'] ?? null);
        $requested = $this->render($pdo, 'pix');
        $this->assertCancelled($requested, 'Cobrança cancelada');
        self::assertStringNotContainsString('Se uma nova cobrança', $requested);
        $this->assertStatusMatchesPage($pdo, $requested, 'pix', 'cancelled');

        // The cancellation handler confirms the request without rewriting the projection.
        $store->markStatus($attempt['id'], 'cancelled');
        $cancelled = $this->render($pdo, 'pix');
        $this->assertCancelled($cancelled, 'Cobrança cancelada');
        self::assertSame($this->attribute($requested, 'revision'), $this->attribute($cancelled, 'revision'));
        $this->assertStatusMatchesPage($pdo, $cancelled, 'pix', 'cancelled');
    }

    public function testProjectedCancellationAndInvoiceWithoutChargeAreNotShownAsPreparing(): void
    {
        [$pdo, $store] = $this->fixture('Cancelled');
        $withoutCharge = $this->render($pdo, 'pix');
        $this->assertCancelled($withoutCharge, 'Cobrança cancelada');
        $this->assertStatusMatchesPage($pdo, $withoutCharge, 'pix', 'cancelled');

        $attempt = $store->ensureCurrent(109, 7, 'pix', 1200);
        $store->complete($attempt['id'], 'remote-109', 'cancelled', ['state' => 'cancelled', 'amount' => '12,00']);
        $projected = $this->render($pdo, 'pix');
        $this->assertCancelled($projected, 'Cobrança cancelada');
        $this->assertStatusMatchesPage($pdo, $projected, 'pix', 'cancelled');
    }

    public function testExpiredPixIsTerminalAndPointsToANewCharge(): void
    {
        [$pdo, $store] = $this->fixture();
        $attempt = $store->ensureCurrent(109, 7, 'pix', 1200);
        $store->complete($attempt['id'], 'remote-109', 'expired', [
            'state' => 'expired', 'copyPaste' => 'pix-code-109', 'qrCodeImageUrl' => self::QR,
        ]);
        $html = $this->render($pdo, 'pix');
        $this->assertCancelled($html, 'Cobrança expirada');
        self::assertStringContainsString('Se uma nova cobrança for emitida, ela aparecerá automaticamente nesta página.', $html);
        $this->assertStatusMatchesPage($pdo, $html, 'pix', 'expired');
    }

    public function testAdministrativeCancellationHidesTheBoletoDocumentAndDigitableLine(): void
    {
        [$pdo, $store] = $this->fixture();
        $attempt = $store->ensureCurrent(109, 7, 'boleto', 1200, '2026-10-10');
        $store->complete($attempt['id'], 'boleto-109', 'ready', [
            'state' => 'ready', 'amount' => '12,00', 'digitableLine' => '34191.79001 01043.510047', 'barcode' => '3419179',
            'copyPaste' => '', 'qrCodeImageUrl' => '', 'pdfUrl' => 'modules/addons/pagou_payments/download.php?attempt=x',
            'dueDate' => '2026-10-10',
        ]);
        self::assertStringContainsString('34191.79001 01043.510047', $this->render($pdo, 'boleto'));

        self::assertTrue($this->runtime($pdo, 'Unpaid')->requestInvoiceCancellation(109, 'Cliente pediu o cancelamento', 1));
        $requested = $this->render($pdo, 'boleto');
        $this->assertCancelled($requested, 'Cancelamento em andamento');
        self::assertStringContainsString('Se uma nova cobrança for emitida', $requested);
        $this->assertStatusMatchesPage($pdo, $requested, 'boleto', 'cancel_requested');

        $store->markStatus($attempt['id'], 'cancelled');
        $cancelled = $this->render($pdo, 'boleto');
        $this->assertCancelled($cancelled, 'Cobrança cancelada');
        $this->assertStatusMatchesPage($pdo, $cancelled, 'boleto', 'cancelled');

        $pdo->exec("UPDATE tblinvoices SET status = 'Cancelled'");
        $closedInvoice = $this->render($pdo, 'boleto');
        $this->assertCancelled($closedInvoice, 'Cobrança cancelada');
        self::assertStringNotContainsString('Se uma nova cobrança', $closedInvoice);
        $this->assertStatusMatchesPage($pdo, $closedInvoice, 'boleto', 'cancelled');
    }

    public function testUnpaidInvoiceShowsTheNewestReplacementInsteadOfTheCancelledCharge(): void
    {
        [$pdo, $store] = $this->fixture();
        $old = $store->ensureCurrent(109, 7, 'pix', 1200);
        $store->complete($old['id'], 'remote-old', 'pending', [
            'state' => 'pending', 'amount' => '12,00', 'copyPaste' => 'old-pix-code', 'qrCodeImageUrl' => self::QR,
        ]);
        $replacement = $store->ensureCurrent(109, 7, 'pix', 1300);
        self::assertSame('cancel_requested', $store->find($old['id'])['status'] ?? null);

        $preparing = $this->render($pdo, 'pix');
        self::assertStringContainsString('Preparando pagamento', $preparing);
        self::assertStringNotContainsString('old-pix-code', $preparing);
        self::assertStringNotContainsString('pagou-qr', $preparing);
        $this->assertStatusMatchesPage($pdo, $preparing, 'pix', 'queued');

        $store->markStatus($old['id'], 'cancelled');
        $store->complete($replacement['id'], 'remote-new', 'pending', [
            'state' => 'pending', 'amount' => '13,00', 'copyPaste' => 'new-pix-code', 'qrCodeImageUrl' => self::QR,
        ]);
        $ready = $this->render($pdo, 'pix');
        self::assertStringContainsString('new-pix-code', $ready);
        self::assertStringContainsString('Aguardando pagamento', $ready);
        self::assertStringNotContainsString('old-pix-code', $ready);
        $status = $this->assertStatusMatchesPage($pdo, $ready, 'pix', 'pending');

        // A later reconciliation of the cancelled charge refreshes its projection after the replacement.
        $store->complete($old['id'], 'remote-old', 'cancelled', [
            'state' => 'cancelled', 'copyPaste' => 'old-pix-code', 'qrCodeImageUrl' => self::QR,
        ]);
        $afterReconciliation = $this->render($pdo, 'pix');
        self::assertStringContainsString('new-pix-code', $afterReconciliation);
        self::assertStringNotContainsString('Cobrança cancelada', $afterReconciliation);
        self::assertSame($status, $this->assertStatusMatchesPage($pdo, $afterReconciliation, 'pix', 'pending'));
    }

    public function testPaymentOnTheEarlierChargeKeepsPriorityOverTheSupersededReplacement(): void
    {
        [$pdo, $store] = $this->fixture();
        $old = $store->ensureCurrent(109, 7, 'pix', 1200);
        $store->complete($old['id'], 'remote-old', 'pending', ['state' => 'pending', 'copyPaste' => 'old-pix-code']);
        $replacement = $store->ensureCurrent(109, 7, 'pix', 1300);
        $store->complete($old['id'], 'remote-old', 'paid', ['state' => 'paid']);
        $store->supersedeInvoice(109);
        self::assertSame('superseded', $store->find($replacement['id'])['status'] ?? null);

        $html = $this->render($pdo, 'pix');
        self::assertStringContainsString('Confirmando pagamento', $html);
        self::assertStringNotContainsString('Cobrança substituída', $html);
        self::assertStringNotContainsString('old-pix-code', $html);
        // Without the native receipt the provider status is still shown as a confirmation in progress.
        $this->assertStatusMatchesPage($pdo, $html, 'pix', 'processing');
    }

    private function assertCancelled(string $html, string $label): void
    {
        self::assertStringContainsString('<span class="pagou-status">' . $label . '</span>', $html);
        self::assertStringContainsString('<p role="status">Não efetue um novo pagamento com os dados desta cobrança.</p>', $html);
        foreach (
            [
                'Preparando', 'Aguardando pagamento', 'Pronto para pagamento', 'pagou-qr', 'pagou-copy', 'Linha digitável',
                'Visualizar boleto', 'Baixar boleto', 'fatura.pagou.com.br', 'download.php', 'Vencimento', 'Valor da cobrança',
                'pagou-document-loading', 'data-pagou-progress-token', 'pix-code-109', '34191.79001',
                'A confirmação do pagamento aparecerá automaticamente nesta página.',
                'O boleto e a confirmação do pagamento serão atualizados',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        self::assertStringNotContainsString("\u{2014}", $html);
    }

    /** @return array<string, mixed> */
    private function assertStatusMatchesPage(PDO $pdo, string $html, string $method, string $state): array
    {
        $status = (new ClientPaymentStatus($pdo))->read(109, $method, 7, false);
        self::assertSame('ok', $status['status']);
        self::assertSame($state, $status['state'] ?? null);
        self::assertSame($this->attribute($html, 'state'), $status['state']);
        self::assertSame($this->attribute($html, 'revision'), $status['revision'] ?? null);
        self::assertSame($this->attribute($html, 'invoice-state'), $status['invoiceState'] ?? null);

        return $status;
    }

    private function attribute(string $html, string $name): string
    {
        self::assertSame(1, preg_match('/ data-pagou-' . preg_quote($name, '/') . '="([^"]*)"/', $html, $match));

        return $match[1];
    }

    private function render(PDO $pdo, string $method): string
    {
        $status = (string) $pdo->query('SELECT status FROM tblinvoices WHERE id = 109')->fetchColumn();

        return $this->runtime($pdo, $status)->renderInvoice(['invoiceid' => 109, 'amount' => '12.00'], $method);
    }

    private function runtime(PDO $pdo, string $invoiceStatus): WhmcsRuntime
    {
        return new WhmcsRuntime(
            $pdo,
            new AddonSettings([]),
            new PdoOperationOutbox($pdo),
            static fn (string $command): array => $command === 'GetInvoice' ? [
                'result' => 'success', 'invoiceid' => 109, 'userid' => 7, 'status' => $invoiceStatus,
                'paymentmethod' => 'pagou_pix', 'total' => '12.00', 'balance' => '12.00', 'duedate' => '2026-10-10',
            ] : ['result' => 'success'],
        );
    }

    /** @return array{PDO, PaymentAttemptStore} */
    private function fixture(string $invoiceStatus = 'Unpaid'): array
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, total TEXT, credit TEXT, status TEXT)');
        $pdo->prepare("INSERT INTO tblinvoices VALUES (109, 7, '12.00', '0.00', ?)")->execute([$invoiceStatus]);

        return [$pdo, new PaymentAttemptStore($pdo)];
    }
}
