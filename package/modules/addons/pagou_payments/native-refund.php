<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/init.php';
require_once dirname(__DIR__, 2) . '/gateways/pagou/bootstrap.php';

use Pagou\Payments\Admin\Security\Csrf;
use Pagou\Whmcs\Application\Runtime\RuntimeFactory;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    $admin = (new \WHMCS\Authentication\CurrentUser())->admin();
    if (
        $admin === null || $admin->isDisabled || !$admin->hasPermission('Manage Invoice')
        || !$admin->hasPermission('Refund Invoice Payments') || !array_key_exists('pagou_payments', $admin->getModulePermissions())
    ) {
        http_response_code(403);
        echo '{"status":"forbidden","message":"Seu perfil não possui acesso a reembolsos do Pagou."}';
        exit;
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET', 'POST'], true)) {
        http_response_code(405);
        echo '{"status":"invalid_method"}';
        exit;
    }
    $input = $method === 'POST' ? $_POST : $_GET;
    $invoice = filter_var($input['invoice_id'] ?? null, FILTER_VALIDATE_INT);
    $account = filter_var($input['transaction_id'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($invoice) || $invoice < 1 || !is_int($account) || $account < 1) {
        throw new InvalidArgumentException('Fatura ou transação inválida.');
    }
    $action = 'read';
    if ($method === 'POST') {
        (new Csrf())->assertValid(is_string($input['token'] ?? null) ? $input['token'] : null);
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';
        if ($action !== 'refresh') {
            throw new InvalidArgumentException('Ação inválida.');
        }
    }
    $result = RuntimeFactory::runtime()->nativePixRefund($invoice, $account, $action);
    echo json_encode(['status' => 'ok'] + $result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $error instanceof DomainException || $error instanceof InvalidArgumentException
        ? $error->getMessage() : 'Não foi possível conferir a devolução. Atualize a fatura e confira o pedido existente antes de continuar.'], JSON_THROW_ON_ERROR);
}
