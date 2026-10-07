<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    return;
}

require_once dirname(__DIR__, 2) . '/gateways/pagou/bootstrap.php';

use Pagou\Whmcs\Application\Runtime\RuntimeFactory;

// Manual creation prepares the document before WHMCS builds the outgoing invoice email.
add_hook('InvoiceCreationPreEmail', 1, static function (array $vars): void {
    if (!in_array($vars['source'] ?? '', ['adminarea', 'api'], true)) {
        return;
    }
    try {
        $runtime = RuntimeFactory::runtime();
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        $runtime->scheduleInvoice($invoiceId);
        $runtime->advanceInvoice($invoiceId);
    } catch (Throwable $exception) {
        logActivity('Pagou Payments: falha ao preparar a cobrança manual. ' . $exception::class);
    }
});

add_hook('InvoiceCreated', 1, static function (array $vars): void {
    try {
        $runtime = RuntimeFactory::runtime();
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        $runtime->scheduleInvoice($invoiceId);
        if (in_array($vars['source'] ?? '', ['adminarea', 'api'], true)) {
            $runtime->advanceInvoice($invoiceId);
        }
    } catch (Throwable $exception) {
        logActivity('Pagou Payments: não foi possível preparar a cobrança da fatura. ' . $exception::class);
    }
});

add_hook('InvoiceChangeGateway', 1, static function (array $vars): void {
    try {
        $runtime = RuntimeFactory::runtime();
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        $runtime->scheduleInvoice($invoiceId);
        $runtime->advanceInvoice($invoiceId);
    } catch (Throwable $exception) {
        logActivity('Pagou Payments: falha local ao atualizar o meio da fatura. ' . $exception::class);
    }
});

add_hook('ViewInvoiceDetailsPage', 1, static function (array $vars): void {
    try {
        RuntimeFactory::runtime()->scheduleInvoice((int) ($vars['invoiceid'] ?? 0));
    } catch (Throwable $exception) {
        logActivity('Pagou Payments: falha local ao preparar a cobrança exibida. ' . $exception::class);
    }
});

add_hook('ClientAreaPageViewInvoice', 1, static function (array $vars): array {
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        $pdo = RuntimeFactory::pdo();
        $invoice = $pdo->prepare('SELECT paymentmethod FROM tblinvoices WHERE id = :id');
        $invoice->execute(['id' => $invoiceId]);
        if ($invoice->fetchColumn() !== 'pagou_creditcard') {
            return [];
        }
        $actor = new \WHMCS\Authentication\CurrentUser();
        $admin = $actor->admin();
        $allowedAdmin = $admin !== null && !$admin->isDisabled
            && ($admin->hasPermission('View Invoice') || $admin->hasPermission('Manage Invoice'));
        $snapshot = (new \Pagou\Whmcs\Application\Runtime\ClientPaymentStatus($pdo))->read(
            $invoiceId,
            'card',
            (int) ($actor->client()->id ?? 0),
            $allowedAdmin,
        );
        if (($snapshot['status'] ?? '') !== 'ok' || ($snapshot['state'] ?? '') === 'not_started') {
            return [];
        }
        $panel = \Pagou\Whmcs\Presentation\CardInvoiceStatus::render($invoiceId, $snapshot);
        return ['paymentbutton' => $panel . (\Pagou\Whmcs\Presentation\CardInvoiceStatus::blocksPayment($snapshot['state'] ?? '')
            ? '' : (string) ($vars['paymentbutton'] ?? ''))];
    } catch (Throwable $exception) {
        logActivity('Pagou Payments: consulta local do cartão indisponível. ' . $exception::class);
        return [];
    }
});

// The client script also reacts when a Pagou method is chosen on an invoice
// that does not use Pagou yet; it guards itself against a second load.
add_hook('ClientAreaFooterOutput', 1, static function (array $vars): string {
    if (($vars['filename'] ?? '') !== 'viewinvoice') {
        return '';
    }
    $assets = __DIR__ . '/assets/';

    return '<link rel="stylesheet" href="modules/addons/pagou_payments/assets/client.css?v=' . substr(hash_file('sha256', $assets . 'client.css') ?: 'client', 0, 12) . '">'
        . '<script src="modules/addons/pagou_payments/assets/client.js?v=' . substr(hash_file('sha256', $assets . 'client.js') ?: 'client', 0, 12) . '" defer></script>';
});

add_hook('InvoicePaid', 1, static function (array $vars): void {
    try {
        RuntimeFactory::runtime()->closePaidInvoice((int) ($vars['invoiceid'] ?? 0));
    } catch (Throwable $exception) {
        logActivity('Pagou Payments: falha local ao encerrar cobranças irmãs. ' . $exception::class);
    }
});

add_hook('InvoiceCancelled', 1, static function (array $vars): void {
    try {
        RuntimeFactory::runtime()->scheduleInvoice((int) ($vars['invoiceid'] ?? 0));
    } catch (Throwable $exception) {
        logActivity('Pagou Payments: falha local ao cancelar a cobrança da fatura. ' . $exception::class);
    }
});

add_hook('EmailPreSend', 1, static function (array $vars): array {
    try {
        return RuntimeFactory::runtime()->emailPreSend($vars);
    } catch (Throwable $exception) {
        logActivity('Pagou Payments: falha local ao preparar e-mail. ' . $exception::class);

        return [];
    }
});

add_hook('EmailTplMergeFields', 1, static function (array $vars): array {
    if (($vars['type'] ?? null) !== 'invoice') {
        return [];
    }

    return [
        'pagou_boleto_line' => 'Linha digitável do boleto Pagou',
        'pagou_boleto_url' => 'Link do boleto na Pagou, abre sem login',
        'pagou_boleto_pdf_url' => 'Link protegido para o PDF do boleto Pagou',
        'pagou_pix_copy_paste' => 'Pix copia e cola da cobrança Pagou',
        'pagou_pix_qr_code' => 'Endereço da imagem do QR Code Pix da cobrança Pagou, para usar em uma tag img',
    ];
});

add_hook('AdminInvoicesControlsOutput', 1, static function (array $vars): string {
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        $summary = RuntimeFactory::runtime()->adminSummary($invoiceId);
        $token = function_exists('generate_token') ? (string) generate_token('plain') : (string) ($_SESSION['tkval'] ?? '');
        return \Pagou\Payments\Admin\InvoiceControls::render($summary, $invoiceId, $token);
    } catch (Throwable) {
        return '';
    }
});

// Proactive admin alerts run after WHMCS finishes its scheduled tasks and never interrupt the cron.
add_hook('AfterCronJob', 1, static function (): void {
    try {
        \Pagou\Whmcs\Application\Runtime\AdminAlerts::fromRuntime()->run();
    } catch (Throwable $exception) {
        logActivity('Pagou Payments: alertas administrativos indisponíveis nesta execução do cron. ' . $exception::class);
    }
});

add_hook('AddTransaction', 1, static function (array $vars): void {
    if (($vars['gateway'] ?? '') !== 'pagou_pix' || (float) ($vars['amountout'] ?? 0) <= 0) {
        return;
    }
    try {
        RuntimeFactory::runtime()->confirmNativePixRefunds((int) ($vars['invoiceid'] ?? $vars['invocieid'] ?? 0));
    } catch (Throwable $exception) {
        logActivity('Pagou Payments: registro do reembolso será conferido pelo worker. ' . $exception::class);
    }
});

add_hook(
    'AdminAreaHeadOutput',
    1,
    static fn (): string => '<link rel="stylesheet" href="../modules/addons/pagou_payments/assets/invoice-admin.css?v='
        . substr(hash_file('sha256', __DIR__ . '/assets/invoice-admin.css') ?: 'admin', 0, 12) . '">'
        . '<link rel="stylesheet" href="../modules/addons/pagou_payments/assets/payment-parties.css?v='
        . substr(hash_file('sha256', __DIR__ . '/assets/payment-parties.css') ?: 'parties', 0, 12) . '">'
        . '<script src="../modules/addons/pagou_payments/assets/invoice-admin.js?v='
        . substr(hash_file('sha256', __DIR__ . '/assets/invoice-admin.js') ?: 'admin', 0, 12) . '" defer></script>'
        . '<script src="../modules/addons/pagou_payments/assets/pix-refund-admin.js?v='
        . substr(hash_file('sha256', __DIR__ . '/assets/pix-refund-admin.js') ?: 'refund', 0, 12) . '" defer></script>',
);

add_hook('AdminHomeWidgets', 1, static function () {
    require_once __DIR__ . '/DashboardWidget.php';
    return \WHMCS\Module\Widget\PagouOfficialSummary::allowed() ? new \WHMCS\Module\Widget\PagouOfficialSummary() : null;
});

add_hook('AdminAreaHeadOutput', 1, static function (): string {
    return '<script defer src="../modules/addons/pagou_payments/assets/widget.js?v='
        . rawurlencode(trim((string) file_get_contents(__DIR__ . '/VERSION'))) . '"></script>';
});
