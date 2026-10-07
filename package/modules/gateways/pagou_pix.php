<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    exit('Acesso direto não permitido.');
}

require_once __DIR__ . '/pagou/bootstrap.php';

use Pagou\Whmcs\Application\Runtime\RuntimeFactory;
use Pagou\Whmcs\Application\Runtime\AddonSettings;

function pagou_pix_MetaData(): array
{
    return ['DisplayName' => 'Pagou - Pix', 'APIVersion' => '1.1', 'gatewayType' => 'Bank'];
}

function pagou_pix_config(): array
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'Pagou - Pix'],
        'PagouSettings' => pagou_gateway_settings_notice(
            'pix',
            'Pix',
            'Emissão, vencimento, multa, juros, limites, acréscimos, e-mail e apresentação',
        ),
    ];
}

function pagou_pix_link(array $params): string
{
    try {
        return RuntimeFactory::runtime()->renderInvoice($params, 'pix');
    } catch (Throwable) {
        $invoiceId = (int) ($params['invoiceid'] ?? 0);
        try {
            $hide = AddonSettings::fromPdo(RuntimeFactory::pdo())->boolean('pix_hide_on_error', true);
        } catch (Throwable) {
            $hide = true;
        }
        $detail = !$hide && $invoiceId > 0 ? ' Se persistir, informe a fatura #' . $invoiceId . ' ao suporte.' : '';

        return '<div class="alert alert-info pagou-payment">Não foi possível exibir o Pix agora.' . $detail . '</div>';
    }
}

/** @param array<string, mixed> $params @return array<string, mixed> */
function pagou_pix_refund(array $params): array
{
    try {
        return RuntimeFactory::runtime()->refundPix(
            (int) ($params['invoiceid'] ?? 0),
            (string) ($params['transid'] ?? ''),
            \Pagou\Whmcs\Domain\Money::fromDecimal((string) ($params['amount'] ?? '0'))->centavos(),
            (int) ($_SESSION['adminid'] ?? 0),
        );
    } catch (Throwable) {
        return ['status' => 'declined', 'declinereason' => 'Não foi possível confirmar o reembolso. Confira o pedido existente no Pagou antes de tentar novamente.', 'rawdata' => ['result' => 'refund_requires_review']];
    }
}
