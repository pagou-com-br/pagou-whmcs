<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\UI\Admin;

use Pagou\Payments\Admin\InvoiceControls;
use PHPUnit\Framework\TestCase;

final class InvoiceControlsTest extends TestCase
{
    public function testRefundCardOnlyShowsStatusAndNeverDuplicatesTheNativeRefundForm(): void
    {
        $summary = ['method' => 'pix', 'state' => 'paid', 'attemptId' => 'original', 'refund' => ['status' => 'available', 'maximum' => '12.00', 'transactionId' => 'receipt']];
        self::assertStringNotContainsString('Reembolsar Pix', InvoiceControls::render($summary, 19, 'csrf'));
        $html = InvoiceControls::render($summary + ['canRefund' => true], 19, 'csrf');
        self::assertStringNotContainsString('Reembolsar Pix', $html);
        self::assertStringNotContainsString('name="transaction_id"', $html);
        self::assertStringNotContainsString('<form', $html);
        $summary['refund'] = ['status' => 'applied', 'state' => 'partially_refunded', 'partial' => true, 'amount' => '4,00', 'providerId' => '<refund>'];
        $html = InvoiceControls::render($summary, 19, 'csrf');
        self::assertStringContainsString('Reembolso parcial confirmado', $html);
        self::assertStringContainsString('&lt;refund&gt;', $html);
        self::assertStringNotContainsString('Solicitar reembolso', $html);
    }

    public function testInteractiveControlsKeepInvoiceAndAttemptIdentityAndLoading(): void
    {
        $html = InvoiceControls::render(['state' => 'ready', 'method' => 'boleto', 'attemptId' => 'attempt-test', 'remoteId' => '<script>'], 10, 'test-token');
        self::assertStringContainsString('invoice-action.php', $html);
        self::assertStringContainsString('data-auto-progress="0"', $html);
        self::assertStringContainsString('data-invoice-id="10"', $html);
        self::assertStringContainsString('name="expected_attempt" value="attempt-test"', $html);
        self::assertStringContainsString('name="token" value="test-token"', $html);
        self::assertStringContainsString('Gerar novo boleto', $html);
        self::assertStringContainsString('pagou-invoice-loading-layer', $html);
        self::assertStringNotContainsString('<script>', $html);
        // The only addon link is this invoice's history, in a new tab.
        self::assertSame(1, substr_count($html, 'addonmodules.php'));
        self::assertStringContainsString('target="_blank" rel="noopener" href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=10">', $html);
        self::assertStringContainsString('Ver histórico</a>', $html);
    }

    public function testEveryCardLinksToTheHistoryAndThePayerDocumentToTheSearch(): void
    {
        foreach (['paid', 'refunded', 'cancelled', 'ready'] as $state) {
            $html = InvoiceControls::render(['state' => $state, 'method' => 'pix', 'attemptId' => 'a', 'remoteId' => 'r'], 7, 'token');
            self::assertStringContainsString('view=charge&amp;invoice=7">', $html, $state);
        }
        $party = \Pagou\Payments\Admin\View\PaymentParties::party(['payerName' => 'Pagador', 'payerDocument' => '27823036860'], true);
        self::assertStringContainsString('<a class="pagou-party-search" href="addonmodules.php?module=pagou_payments&amp;view=search&amp;q=27823036860" target="_blank" rel="noopener"', $party);
        self::assertStringContainsString('CPF: 278.230.368-60', $party);
        // Only the payer's document is a shortcut; the issued one stays text.
        $issued = \Pagou\Payments\Admin\View\PaymentParties::party(['issuedName' => 'Cliente', 'issuedDocument' => '27823036860'], false);
        self::assertStringNotContainsString('pagou-party-search', $issued);
    }

    public function testBoletoCardOpensThePagouBoletoPageAndPdf(): void
    {
        $html = InvoiceControls::render(['state' => 'ready', 'method' => 'boleto', 'attemptId' => 'attempt-test', 'remoteId' => 'b3f2560a-747a-46b7-9dfd-6fd7cf4f3e1f'], 10, 'token');
        self::assertStringContainsString('href="https://fatura.pagou.com.br/boleto/b3f2560a-747a-46b7-9dfd-6fd7cf4f3e1f">', $html);
        self::assertStringContainsString('href="https://fatura.pagou.com.br/boleto/pdf/b3f2560a-747a-46b7-9dfd-6fd7cf4f3e1f">', $html);
        self::assertStringContainsString('Ver boleto</a>', $html);
        self::assertStringContainsString('PDF do boleto</a>', $html);
        self::assertStringContainsString('Gerar novo boleto', $html);
        foreach ([['state' => 'cancelled'], ['method' => 'pix'], ['remoteId' => '']] as $change) {
            $other = InvoiceControls::render(array_replace(['state' => 'ready', 'method' => 'boleto', 'attemptId' => 'a', 'remoteId' => 'b'], $change), 10, 'token');
            self::assertStringNotContainsString('fatura.pagou.com.br/boleto', $other);
        }
    }

    public function testPaidInvoiceDoesNotOfferCancellationOrReplacement(): void
    {
        $html = InvoiceControls::render(['state' => 'paid', 'method' => 'boleto', 'attemptId' => 'test', 'remoteId' => 'charge-test',
            'identities' => [['paid' => true, 'method' => 'boleto', 'pagouId' => 'charge-test']]], 10, 'token');
        self::assertStringNotContainsString('<form', $html);
        self::assertStringContainsString('Pagamento confirmado', $html);
    }

    public function testRemotePaidStateRequiresAppliedReceiptForTheSameCharge(): void
    {
        $summary = ['state' => 'paid', 'method' => 'pix', 'attemptId' => 'test', 'remoteId' => 'pix-current',
            'identities' => [['paid' => true, 'method' => 'pix', 'pagouId' => 'pix-previous', 'payerName' => 'Outro recebimento']]];
        $html = InvoiceControls::render($summary, 10, 'token');
        self::assertStringContainsString('Confirmando na fatura', $html);
        self::assertStringNotContainsString('Pagamento confirmado', $html);
        self::assertStringNotContainsString('<form', $html);

        $summary['identities'][0] = ['paid' => true, 'method' => 'pix', 'pagouId' => 'pix-current',
            'payerName' => '<Pagador>', 'payerDocument' => '11144477735', 'e2e' => 'E-synthetic-receipt'];
        $html = InvoiceControls::render($summary, 10, 'token');
        self::assertStringContainsString('Pagamento confirmado', $html);
        self::assertStringContainsString('&lt;Pagador&gt;', $html);
        self::assertStringContainsString('E-synthetic-receipt', $html);
        self::assertStringNotContainsString('Outra pessoa pode pagar', $html);
        self::assertSame(1, substr_count($html, '<span>ID Pagou</span>'));
    }
}
