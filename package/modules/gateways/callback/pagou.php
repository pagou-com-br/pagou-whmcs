<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/init.php';
require_once dirname(__DIR__) . '/pagou/bootstrap.php';

use Pagou\Whmcs\Application\Runtime\RuntimeFactory;

$rawBody = file_get_contents('php://input');
$rawBody = is_string($rawBody) ? $rawBody : '';
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headers = is_array($headers) ? array_map(static fn (mixed $value): string => (string) $value, $headers) : [];

$receipt = null;
try {
    $receipt = RuntimeFactory::webhook()->receive($headers, $rawBody);
    $body = json_encode(['status' => $receipt->outcome], JSON_THROW_ON_ERROR);
    http_response_code($receipt->httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
} catch (Throwable) {
    $receipt = null;
    $body = '{"status":"temporarily_unavailable"}';
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
}
if ($receipt === null || $receipt->outcome !== 'processed' || $receipt->deliveryKey === null) {
    echo $body;
    return;
}

// Acknowledge Pagou first, then confirm the payment now instead of at the next cron run.
// The same fenced worker operations run here, so the cron remains a safe fallback.
ignore_user_abort(true);
// WHMCS runs every InvoicePaid hook inside the booking call; a page time limit must not cut it short.
@set_time_limit(0);
header('Content-Length: ' . strlen($body));
header('Connection: close');
echo $body;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
} else {
    while (ob_get_level() > 0 && ob_end_flush()) {
        // Release every buffer so the response reaches Pagou before processing.
    }
    flush();
}
try {
    RuntimeFactory::runtime()->advanceWebhook($receipt->deliveryKey);
} catch (Throwable) {
    // The worker started by cron processes the queued confirmation.
}
