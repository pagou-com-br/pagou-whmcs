<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/init.php';
require_once dirname(__DIR__, 2) . '/gateways/pagou/bootstrap.php';

use Pagou\Whmcs\Application\Runtime\InvoiceProgressToken;
use Pagou\Whmcs\Application\Runtime\RuntimeFactory;

// The CPF or CNPJ typed on an invoice whose charge was refused for it.
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$invoiceId = filter_var($_POST['invoice'] ?? null, FILTER_VALIDATE_INT);
$invoiceId = is_int($invoiceId) ? $invoiceId : 0;
$method = is_string($_POST['method'] ?? null) ? strtolower($_POST['method']) : '';
$json = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
$respond = static function (int $code, array $payload) use ($json, $invoiceId): void {
    if (!$json) {
        // Without the page script, the invoice itself shows the result.
        header('Location: ../../../viewinvoice.php?id=' . $invoiceId, true, 303);
        exit;
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond(405, ['status' => 'forbidden']);
}
try {
    $clientId = (int) ((new \WHMCS\Authentication\CurrentUser())->client()->id ?? 0);
    if ($clientId < 1 || $invoiceId < 1 || !InvoiceProgressToken::valid($invoiceId, $_POST['token'] ?? null)) {
        $respond(403, ['status' => 'forbidden', 'message' => 'Atualize a página e tente novamente.']);
    }
    $result = RuntimeFactory::runtime()->saveInvoiceDocument($invoiceId, $method, $clientId, is_string($_POST['document'] ?? null) ? $_POST['document'] : '');
    $respond(['ok' => 200, 'invalid' => 422, 'forbidden' => 403, 'limited' => 429][$result['status']] ?? 400, $result);
} catch (\Throwable) {
    $respond(503, ['status' => 'unavailable', 'message' => 'Não foi possível salvar agora. Tente novamente em instantes.']);
}
