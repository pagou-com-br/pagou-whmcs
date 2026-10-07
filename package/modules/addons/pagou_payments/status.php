<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/init.php';
require_once dirname(__DIR__, 2) . '/gateways/pagou/bootstrap.php';

use Pagou\Whmcs\Application\Runtime\ClientPaymentStatus;
use Pagou\Whmcs\Application\Runtime\RuntimeFactory;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$invoiceId = filter_input(INPUT_GET, 'invoice', FILTER_VALIDATE_INT);
$method = is_string($_GET['method'] ?? null) ? strtolower($_GET['method']) : '';
try {
    $actor = new \WHMCS\Authentication\CurrentUser();
    $clientId = (int) ($actor->client()->id ?? 0);
    $admin = $actor->admin();
    $authorizedAdmin = $admin !== null && !$admin->isDisabled
        && ($admin->hasPermission('View Invoice') || $admin->hasPermission('Manage Invoice'));
    $payload = (new ClientPaymentStatus(RuntimeFactory::pdo()))->read(
        is_int($invoiceId) ? $invoiceId : 0,
        $method,
        $clientId,
        $authorizedAdmin,
    );
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $payload['status'] === 'ok') {
        if ($method !== 'boleto' || !\Pagou\Whmcs\Application\Runtime\InvoiceProgressToken::valid($invoiceId, $_POST['progress_token'] ?? null)) {
            http_response_code(403);
            echo '{"status":"forbidden"}';
            exit;
        }
        $check = RuntimeFactory::pdo()->prepare("SELECT 1 FROM tblinvoices WHERE id = :id AND status = 'Unpaid' AND paymentmethod = 'pagou_boleto'");
        $check->execute(['id' => $invoiceId]);
        if ($check->fetchColumn() !== false) {
            $report = RuntimeFactory::runtime()->advanceInvoice($invoiceId);
            $payload = (new ClientPaymentStatus(RuntimeFactory::pdo()))->read($invoiceId, $method, $clientId, $authorizedAdmin);
            $payload['operationIssue'] = $report->failed > 0 || $report->uncertain > 0;
        }
    }
    http_response_code(['ok' => 200, 'forbidden' => 403, 'not_found' => 404][$payload['status']]);
    echo json_encode($payload, JSON_THROW_ON_ERROR);
} catch (\Throwable) {
    http_response_code(503);
    echo '{"status":"unavailable"}';
}
