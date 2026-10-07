<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin;

use Pagou\Payments\Admin\Support\Html;

final class InvoiceControls
{
    /** @param array<string, mixed> $summary */
    public static function render(array $summary, int $invoiceId, string $token): string
    {
        $endpoint = '../modules/addons/pagou_payments/invoice-action.php';
        if ($summary === []) {
            return '';
        }
        $state = htmlspecialchars((string) ($summary['state'] ?? 'pending'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $method = htmlspecialchars((string) ($summary['method'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attemptId = htmlspecialchars((string) ($summary['attemptId'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $remoteId = htmlspecialchars((string) ($summary['remoteId'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $identities = $summary['identities'] ?? [];
        $confirmed = array_filter($identities, static fn (array $row): bool => !empty($row['paid'])
            && ($row['pagouId'] ?? '') === ($summary['remoteId'] ?? '') && ($row['method'] ?? '') === ($summary['method'] ?? ''));
        $displayState = $state === 'paid' && in_array($method, ['pix', 'boleto'], true) && $confirmed === [] ? 'processing' : $state;
        $token = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $methodLabel = match ($method) {
            'pix' => 'Pix',
            'boleto' => 'Boleto',
            'card' => 'Cartão de crédito',
            default => 'Pagamento',
        };
        $stateLabel = match ($displayState) {
            'ready', 'active' => 'Pronto para pagamento',
            'paid' => 'Pagamento confirmado',
            'processing' => 'Confirmando na fatura',
            'cancel_requested' => 'Cancelamento solicitado',
            'cancelled', 'canceled' => 'Cancelado',
            'refunded' => 'Reembolsado',
            'awaiting_registration' => 'Aguardando registro bancário',
            'unavailable' => 'Indisponível pelas regras configuradas',
            'failed' => 'Falha na emissão',
            default => $method === 'pix' ? 'Aguardando pagamento' : 'Em processamento',
        };
        $stateTone = match ($displayState) {
            'paid', 'ready', 'active' => 'success',
            'cancelled', 'canceled', 'refunded', 'unavailable' => 'muted',
            'failed' => 'danger',
            default => 'warning',
        };
        $refund = is_array($summary['refund'] ?? null) ? $summary['refund'] : [];
        if (isset($refund['state']) && ($refund['status'] ?? '') !== 'rejected') {
            $stateLabel = match ($refund['status']) {
                'applied' => !empty($refund['partial']) ? 'Reembolso parcial confirmado' : 'Reembolso confirmado',
                'confirmed' => 'Registro do reembolso pendente',
                'review' => 'Reembolso em conferência',
                default => 'Reembolso em processamento',
            };
            $stateTone = match ($refund['status']) {
                'applied' => 'success',
                'review' => 'danger',
                default => 'warning',
            };
        }
        $form = static function (string $action, string $label, string $reason, string $tone) use ($invoiceId, $token, $attemptId, $endpoint): string {
            return '<form method="post" action="' . $endpoint . '" '
                . 'class="pagou-invoice-action"><input type="hidden" name="token" value="' . $token . '">'
                . '<input type="hidden" name="expected_attempt" value="' . $attemptId . '">'
                . '<input type="hidden" name="invoice_id" value="' . $invoiceId . '">'
                . '<input type="hidden" name="action" value="' . $action . '">'
                . '<input type="hidden" name="view" value="payments">'
                . '<input type="hidden" name="reason" value="' . $reason . '">'
                . '<button type="button" data-pagou-invoice-submit class="btn btn-sm pagou-invoice-button pagou-invoice-button--' . $tone . '">'
                . $label . '</button></form>';
        };
        $actions = $attemptId === '' || in_array($state, ['paid', 'cancelled', 'canceled', 'refunded', 'unavailable'], true)
            ? ''
            : $form(
                'cancel-invoice',
                'Cancelar cobrança',
                'Cancelamento solicitado pelo administrador no WHMCS.',
                'danger',
            );
        if ($method === 'boleto' && $actions !== '') {
            $actions .= $form(
                'replace-boleto',
                'Gerar novo boleto',
                'Substituição solicitada pelo administrador no WHMCS.',
                'primary',
            );
        }

        $autoProgress = $method === 'boleto'
            && (in_array($state, ['pending', 'queued', 'awaiting_registration'], true)
                || (empty($summary['remoteId']) && in_array($state, ['ready', 'active'], true)));

        $remote = (string) ($summary['remoteId'] ?? '');
        // The full module history of this invoice, whatever its state.
        $history = '<a class="btn btn-sm pagou-invoice-button pagou-invoice-button--link" target="_blank" rel="noopener" href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=' . $invoiceId . '">'
            . Html::icon('activity', 14) . 'Ver histórico</a>';
        // The boleto pages hosted by Pagou, as the customer sees them.
        $documents = $method === 'boleto' && $remote !== '' && !in_array($state, ['cancelled', 'canceled', 'failed', 'unavailable', 'superseded'], true)
            ? '<a class="btn btn-sm pagou-invoice-button pagou-invoice-button--link" target="_blank" rel="noopener noreferrer" href="https://fatura.pagou.com.br/boleto/' . Html::e(rawurlencode($remote)) . '">'
                . Html::icon('barcode', 14) . 'Ver boleto</a>'
                . '<a class="btn btn-sm pagou-invoice-button pagou-invoice-button--link" target="_blank" rel="noopener noreferrer" href="https://fatura.pagou.com.br/boleto/pdf/' . Html::e(rawurlencode($remote)) . '">'
                . Html::icon('download', 14) . 'PDF do boleto</a>'
            : '';
        $methodIcon = match ($method) {
            'pix' => Html::icon('qr-code', 15),
            'boleto' => Html::icon('barcode', 15),
            'card' => Html::icon('card', 15),
            default => '',
        };

        return '<section class="pagou-invoice-admin" data-refund-status="' . Html::e($refund['status'] ?? '') . '" data-refund-token="' . $token . '" data-auto-progress="' . ($autoProgress ? '1' : '0') . '" data-invoice-id="' . $invoiceId . '" data-attempt-id="' . $attemptId . '" data-endpoint="' . $endpoint . '"><header><div class="pagou-invoice-brand">'
            . Html::logo('pagou-invoice-logo')
            . '<strong class="pagou-invoice-method">' . $methodIcon . $methodLabel . '</strong></div><span class="pagou-invoice-state pagou-invoice-state--'
            . $stateTone . '"><span class="pagou-invoice-dot" aria-hidden="true"></span>' . $stateLabel . '</span></header>'
            . \Pagou\Payments\Admin\View\PaymentParties::render($identities, true)
            . '<div class="pagou-invoice-ids">'
            . (in_array($remote, array_column($identities, 'pagouId'), true) ? '' : \Pagou\Payments\Admin\View\CopyField::render('ID Pagou', $remote, 'pagou-remote-' . $invoiceId))
            . \Pagou\Payments\Admin\View\CopyField::render('Tentativa', (string) ($summary['attemptId'] ?? ''), 'pagou-attempt-' . $invoiceId)
            . '</div>'
            . self::refund($refund, $invoiceId)
            . '<footer><div class="pagou-invoice-documents">' . $history . $documents . '</div>' . $actions . '</footer>'
            . '<p class="pagou-invoice-notice" role="status" hidden></p>' . self::loader() . '</section>';
    }

    /** @param array<string,mixed> $refund */
    private static function refund(array $refund, int $invoiceId): string
    {
        $status = (string) ($refund['status'] ?? '');
        if ($refund === [] || $status === 'available') {
            return '';
        }
        $kind = !empty($refund['partial']) ? 'Reembolso parcial' : 'Reembolso total';
        [$title, $detail, $tone] = match ($status) {
            'applied' => [$kind, 'Devolvido ao pagador e registrado no WHMCS.', 'success'],
            'confirmed' => ['Devolução confirmada pela Pagou', 'Conclua o registro pela aba Refund com esta transação. O valor da devolução é preenchido automaticamente.', 'warning'],
            'review' => ['Devolução em conferência', 'Contate o suporte. Não repita o pedido.', 'danger'],
            'rejected' => ['Devolução não aceita', 'Confira a situação com o suporte antes de solicitar outra.', 'danger'],
            default => ['Devolução em processamento', 'Aguardando a confirmação da Pagou.', 'info'],
        };

        return '<div class="pagou-invoice-refund pagou-invoice-refund--' . $tone . '" role="status"><div class="pagou-invoice-refund-main">'
            . '<span class="pagou-invoice-refund-icon" aria-hidden="true">' . Html::icon('refresh', 16) . '</span>'
            . '<div class="pagou-invoice-refund-copy"><strong>' . Html::e($title) . '</strong><small>' . Html::e($detail) . '</small></div>'
            . '<strong class="pagou-invoice-refund-amount">R$ ' . Html::e((string) ($refund['amount'] ?? '')) . '</strong></div>'
            . \Pagou\Payments\Admin\View\CopyField::render('ID do reembolso', (string) ($refund['providerId'] ?? ''), 'pagou-refund-' . $invoiceId)
            . '</div>';
    }

    private static function loader(): string
    {
        return '<div class="pagou-invoice-loading-layer" aria-hidden="true"><div class="pagou-invoice-loading-panel" role="status">'
            . '<span class="pagou-invoice-pixel-loader" aria-hidden="true">' . str_repeat('<span class="pagou-invoice-pixel-cell"></span>', 9) . '</span>'
            . '<div class="pagou-invoice-loading-copy"><strong class="pagou-invoice-loading-label" data-pagou-label="Processando">Processando</strong>'
            . '<small>Aguardando a confirmação da Pagou.</small></div></div></div>';
    }
}
