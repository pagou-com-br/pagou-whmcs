<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Security\Webhook;

use Pagou\Whmcs\Application\Webhook\WebhookEventParser;
use PHPUnit\Framework\TestCase;

final class WebhookEventParserTest extends TestCase
{
    public function testItRecognizesPixBoletoAndCardWithoutAssumingUnknownValues(): void
    {
        $parser = new WebhookEventParser();
        $pix = $parser->parse('{"event_id":"e1","type":"payment.paid","data":{"id":"p1","payment_method":"pix","status":"paid"}}', 'fallback');
        $boleto = $parser->parse('{"event_id":"e2","data":{"id":"b1","payment_method":"boleto","status":"paid"}}', 'fallback');
        $card = $parser->parse('{"event_id":"e3","data":{"id":"c1","payment_method":"credit_card","status":"authorized"}}', 'fallback');
        $unknown = $parser->parse('{"event_id":"e4","data":{"id":"x1","payment_method":"future_method"}}', 'fallback');

        self::assertSame('pix', $pix->paymentMethod);
        self::assertSame('boleto', $boleto->paymentMethod);
        self::assertSame('card', $card->paymentMethod);
        self::assertSame('unknown', $unknown->paymentMethod);
        self::assertFalse($unknown->isKnownPaymentMethod());
    }

    public function testItInfersTheCurrentUnwrappedPagouPixAndBoletoEvents(): void
    {
        $parser = new WebhookEventParser();
        $pix = $parser->parse(
            '{"id":"pix-id","external_id":"external","transaction_id":"tx-pix",'
            . '"e2e_id":"E123","amount":12.34,"payer":{"name":"Cliente"},"description":"Fatura"}',
            'delivery-pix',
        );
        $created = $parser->parse(
            '{"id":"boleto-id","transaction_id":"tx-create","client_code":"invoice-10",'
            . '"payload":{"bar_code":"123","line":"456","qrcode_id":"qr"}}',
            'delivery-created',
        );
        $paid = $parser->parse(
            '{"id":"boleto-id","transaction_id":"tx-paid","client_code":"invoice-10",'
            . '"amount":{"paid":12.34,"original":12.34},"paid_in":"2026-08-22T12:00:00Z",'
            . '"payment_type":"pix"}',
            'delivery-paid',
        );

        self::assertSame(['qrcode.completed', 'pix', 'paid'], [$pix->type, $pix->paymentMethod, $pix->status]);
        self::assertSame(['charge.created', 'boleto', 'ready'], [$created->type, $created->paymentMethod, $created->status]);
        self::assertSame(['charge.paid', 'boleto', 'paid'], [$paid->type, $paid->paymentMethod, $paid->status]);
    }
}
