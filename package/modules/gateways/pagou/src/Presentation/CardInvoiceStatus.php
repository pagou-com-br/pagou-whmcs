<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Presentation;

/** Local status only. Rendering and polling never initiate a card operation. */
final class CardInvoiceStatus
{
    public static function blocksPayment(string $state): bool
    {
        return \Pagou\Whmcs\Payment\Card\CardStatus::blocksNewAttempt($state);
    }

    /** @param array<string, string> $snapshot */
    public static function render(int $invoiceId, array $snapshot, string $baseUrl = ''): string
    {
        if (($snapshot['status'] ?? '') !== 'ok' || $invoiceId < 1) {
            return '';
        }
        $state = $snapshot['state'] ?? 'pending';
        [$label, $message] = match ($state) {
            'paid' => ['Pagamento confirmado', 'A confirmação do pagamento será refletida nesta fatura.'],
            'failed' => ['Pagamento não autorizado', 'Confira o cartão ou escolha outro meio de pagamento.'],
            'cancelled', 'canceled' => ['Pagamento cancelado', 'Esta cobrança foi cancelada.'],
            'reversed', 'refunded' => ['Pagamento estornado', 'O estorno foi confirmado. Confira os detalhes da fatura.'],
            'charged_back' => ['Pagamento em revisão', 'Entre em contato com o atendimento para conferir esta fatura.'],
            'action_required' => ['Autenticação pendente', 'A cobrança precisa de autenticação. Se você não recebeu as instruções, fale com o atendimento.'],
            'uncertain' => ['Aguardando confirmação', 'Ainda estamos conferindo o resultado. Aguarde antes de tentar pagar novamente.'],
            default => ['Confirmando pagamento', 'A confirmação aparecerá automaticamente nesta página. Aguarde antes de tentar pagar novamente.'],
        };
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $assets = rtrim($baseUrl, '/') . ($baseUrl === '' ? '' : '/') . 'modules/addons/pagou_payments/';
        $directory = dirname(__DIR__, 4) . '/addons/pagou_payments/assets/';
        $css = substr(hash_file('sha256', $directory . 'client.css') ?: 'client', 0, 12);
        $js = substr(hash_file('sha256', $directory . 'client.js') ?: 'client', 0, 12);
        $loading = in_array($state, ['created', 'queued', 'dispatching', 'pending', 'authorized'], true);

        return '<link rel="stylesheet" href="' . $escape($assets . 'assets/client.css?v=' . $css) . '">'
            . '<script defer src="' . $escape($assets . 'assets/client.js?v=' . $js) . '"></script>'
            . '<section class="pagou-payment pagou-payment--card" data-pagou-status-url="'
            . $escape($assets . 'status.php?invoice=' . $invoiceId . '&method=card') . '"'
            . ' data-pagou-state="' . $escape($state) . '" data-pagou-revision="' . $escape($snapshot['revision'] ?? '') . '"'
            . ' data-pagou-invoice-state="' . $escape($snapshot['invoiceState'] ?? 'unpaid') . '">'
            . '<header class="pagou-payment__header"><strong>Cartão de crédito</strong></header>'
            . '<p class="pagou-card-progress" role="status">'
            . ($loading ? '<span class="pagou-document-pixels" aria-hidden="true">' . str_repeat('<span></span>', 9) . '</span>' : '')
            . '<strong>' . $escape($label) . '</strong></p>'
            . '<p class="pagou-help">' . $escape($message) . '</p>'
            . '<p class="pagou-help" data-pagou-connection role="status"></p></section>';
    }
}
