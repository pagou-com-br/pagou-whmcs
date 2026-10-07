<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Presentation;

/** Client-facing result with no payment artifacts or administrative information. */
final class PaymentResultView
{
    public static function render(string $state, string $method, string $invoiceState): string
    {
        $paid = $state === 'paid';
        $icon = $paid ? '<path d="m6 12 4 4 8-8"/>' : '<circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/>';
        $title = $paid ? 'Pagamento confirmado' : 'Confirmando pagamento';
        $description = $paid
            ? ($invoiceState === 'paid' ? 'Sua fatura está paga. Tudo certo!' : 'Pagamento registrado. Confira o saldo atualizado da fatura.')
            : 'Pagamento identificado pela Pagou. Estamos conferindo o recebimento na sua fatura.';
        $note = $paid ? 'Você não precisa pagar novamente.' : 'A página será atualizada automaticamente. Não pague novamente.';

        if (in_array($state, ['refund_pending', 'partially_refunded', 'refunded'], true)) {
            $title = match ($state) {
                'partially_refunded' => 'Reembolso parcial confirmado',
                'refunded' => 'Reembolso confirmado',
                default => 'Reembolso em processamento',
            };
            $description = $state === 'refund_pending' ? 'A devolução está sendo acompanhada. Aguarde a confirmação.'
                : 'A devolução foi confirmada e registrada na sua fatura.';
            $note = 'Confira os detalhes no histórico da fatura.';
            $icon = $state === 'refund_pending' ? $icon : '<path d="m6 12 4 4 8-8"/>';
        }

        return '<div class="pagou-result" role="status" aria-live="polite">'
            . '<span class="pagou-result__method">' . ($method === 'pix' ? 'Pix' : 'Boleto') . '</span>'
            . '<span class="pagou-result__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $icon . '</svg></span>'
            . '<h3 class="pagou-result__title">' . $title . '</h3>'
            . '<p class="pagou-result__description">' . $description . '</p>'
            . '<p class="pagou-result__note">' . $note . '</p></div>'
            . '<p class="pagou-help" data-pagou-connection role="status" aria-live="polite" hidden></p>';
    }
}
