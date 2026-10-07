<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/init.php';
require_once dirname(__DIR__, 2) . '/gateways/pagou/bootstrap.php';

use Pagou\Whmcs\Application\Runtime\RuntimeFactory;
use Pagou\Whmcs\Infrastructure\Whmcs\PrivateStorage;

$actor = new \WHMCS\Authentication\CurrentUser();
$clientId = (int) ($actor->client()->id ?? 0);
$admin = $actor->admin();
$authorizedAdmin = $admin !== null && !$admin->isDisabled
    && ($admin->hasPermission('View Invoice') || $admin->hasPermission('Manage Invoice'));
$attemptId = is_string($_GET['attempt'] ?? null) ? $_GET['attempt'] : '';
if (($clientId < 1 && !$authorizedAdmin) || preg_match('/^[a-f0-9-]{36}$/i', $attemptId) !== 1) {
    http_response_code(403);
    exit;
}

$pdo = RuntimeFactory::pdo();
$statement = $pdo->prepare(
    'SELECT a.invoice_id, a.response_json, i.userid FROM pagou_payment_attempts a '
    . 'JOIN tblinvoices i ON i.id = a.invoice_id WHERE a.id = :id AND a.method = :method LIMIT 1'
);
$statement->execute(['id' => $attemptId, 'method' => 'boleto']);
$row = $statement->fetch(PDO::FETCH_ASSOC);
if ($row === false || (!$authorizedAdmin && (int) $row['userid'] !== $clientId)) {
    http_response_code(404);
    exit;
}

try {
    $response = json_decode((string) $row['response_json'], true, 512, JSON_THROW_ON_ERROR);
    $key = $response['artifacts']['local_pdf_key'] ?? null;
    if (!is_string($key) || $key === '') {
        throw new RuntimeException('PDF unavailable.');
    }
    $configured = getenv('PAGOU_PRIVATE_STORAGE_DIR');
    $root = defined('ROOTDIR') ? (string) constant('ROOTDIR') : dirname(__DIR__, 3);
    $directory = is_string($configured) && trim($configured) !== ''
        ? trim($configured)
        : dirname($root) . '/pagou-whmcs-private';
    $contents = (new PrivateStorage($directory, $root))->read($key);
} catch (Throwable) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/pdf');
$disposition = ($_GET['inline'] ?? '') === '1' ? 'inline' : 'attachment';
header('Content-Disposition: ' . $disposition . '; filename="boleto-fatura-' . (int) $row['invoice_id'] . '.pdf"');
header('Content-Length: ' . strlen($contents));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $contents;
