<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    exit('Acesso direto não permitido.');
}

require_once __DIR__ . '/pagou/bootstrap.php';

use Pagou\Whmcs\Application\Runtime\RuntimeFactory;
use Pagou\Whmcs\Application\Runtime\AddonSettings;

function pagou_boleto_MetaData(): array
{
    return ['DisplayName' => 'Pagou - Boleto', 'APIVersion' => '1.1', 'gatewayType' => 'Bank'];
}

function pagou_boleto_config(): array
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'Pagou - Boleto'],
        'PagouSettings' => pagou_gateway_settings_notice(
            'boleto',
            'boleto',
            'Registro, multa, juros, limites, acréscimos, PDF, e-mail e apresentação',
        ),
    ];
}

function pagou_boleto_link(array $params): string
{
    try {
        return RuntimeFactory::runtime()->renderInvoice($params, 'boleto');
    } catch (Throwable) {
        $invoiceId = (int) ($params['invoiceid'] ?? 0);
        try {
            $hide = AddonSettings::fromPdo(RuntimeFactory::pdo())->boolean('boleto_hide_on_error', true);
        } catch (Throwable) {
            $hide = true;
        }
        $detail = !$hide && $invoiceId > 0 ? ' Se persistir, informe a fatura #' . $invoiceId . ' ao suporte.' : '';

        return '<div class="alert alert-info pagou-payment">Não foi possível carregar o boleto agora. Acesse novamente a fatura em alguns instantes.' . $detail . '</div>';
    }
}
