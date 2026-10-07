<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/init.php';
require_once dirname(__DIR__, 2) . '/gateways/pagou/bootstrap.php';

use Pagou\Whmcs\Application\Runtime\PixQrImage;
use Pagou\Whmcs\Application\Runtime\PixQrLink;
use Pagou\Whmcs\Application\Runtime\RuntimeFactory;

// Public on purpose: email clients fetch the Pix QR Code without a WHMCS session.
// The signed address names one charge and a deadline; only a payable Pix is served.
$attemptId = is_string($_GET['a'] ?? null) ? $_GET['a'] : '';
$expiresAt = is_string($_GET['e'] ?? null) ? $_GET['e'] : '';
$signature = is_string($_GET['s'] ?? null) ? $_GET['s'] : '';
$image = null;
try {
    $link = PixQrLink::fromWhmcs();
    if ($link !== null && $link->valid($attemptId, $expiresAt, $signature, time())) {
        $image = (new PixQrImage(RuntimeFactory::pdo()))->find($attemptId);
    }
} catch (Throwable) {
    $image = null;
}

if ($image === null) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}

header('Content-Type: ' . $image['type']);
header('Content-Length: ' . strlen($image['data']));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
echo $image['data'];
