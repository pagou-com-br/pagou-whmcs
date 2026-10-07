<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/init.php';
require_once dirname(__DIR__, 2) . '/gateways/pagou/bootstrap.php';

use Pagou\Payments\Admin\InvoiceControls;
use Pagou\Payments\Admin\Security\Csrf;
use Pagou\Whmcs\Application\Runtime\RuntimeFactory;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    $admin = (new \WHMCS\Authentication\CurrentUser())->admin();
    if (
        $admin === null || $admin->isDisabled || !$admin->hasPermission('Manage Invoice')
        || !array_key_exists('pagou_payments', $admin->getModulePermissions())
    ) {
        http_response_code(403);
        echo '{"status":"forbidden","message":"Entre novamente no administrativo para acompanhar esta operação."}';
        exit;
    }
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!$post && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        echo '{"status":"invalid_method"}';
        exit;
    }
    $input = $post ? $_POST : $_GET;
    $invoiceId = filter_var($input['invoice_id'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($invoiceId) || $invoiceId < 1) {
        throw new InvalidArgumentException('Fatura inválida.');
    }
    $runtime = RuntimeFactory::runtime();
    $summary = $runtime->adminSummary($invoiceId);
    if ($summary === []) {
        throw new InvalidArgumentException('Esta fatura não utiliza Pix ou boleto deste módulo.');
    }
    if ($post) {
        (new Csrf())->assertValid(is_string($input['token'] ?? null) ? $input['token'] : null);
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';
        if (!in_array($action, ['cancel-invoice', 'replace-boleto', 'advance-invoice'], true)) {
            throw new InvalidArgumentException('Ação inválida.');
        }
        if ($action !== 'advance-invoice') {
            // An outdated tab must never replace a newer charge by accident.
            if (
                !is_string($input['expected_attempt'] ?? null) || $input['expected_attempt'] === ''
                || $input['expected_attempt'] !== ($summary['attemptId'] ?? '')
            ) {
                http_response_code(409);
                echo '{"status":"stale","message":"A cobrança mudou. Atualize a página e confira a cobrança antes de solicitar outra ação."}';
                exit;
            }
            $runtime->requestInvoiceCancellation(
                $invoiceId,
                $action === 'replace-boleto' ? 'Substituição solicitada pelo administrador no WHMCS.' : 'Cancelamento solicitado pelo administrador no WHMCS.',
                (int) $admin->id,
                $action === 'replace-boleto',
            );
        }
        $report = $runtime->advanceInvoice($invoiceId);
        $summary = $runtime->adminSummary($invoiceId);
    }
    $token = function_exists('generate_token') ? (string) generate_token('plain') : (string) ($_SESSION['tkval'] ?? '');
    echo json_encode([
        'status' => 'ok',
        'operationIssue' => isset($report) && ($report->failed > 0 || $report->uncertain > 0),
        'state' => $summary['state'] ?? 'pending',
        'attemptId' => $summary['attemptId'] ?? '',
        'remoteId' => $summary['remoteId'] ?? '',
        'pdfReady' => !empty($summary['pdfUrl']),
        'html' => InvoiceControls::render($summary, $invoiceId, $token),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(400);
    if (function_exists('logActivity')) {
        logActivity('Pagou Payments: operação na fatura não concluída. ' . $exception::class);
    }
    echo '{"status":"error","message":"Não foi possível concluir a solicitação. Consulte o diagnóstico do Pagou antes de tentar novamente."}';
}
