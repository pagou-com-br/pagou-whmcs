<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 3) . '/init.php';
require_once dirname(__DIR__, 2) . '/gateways/pagou/bootstrap.php';

Pagou\Whmcs\Application\Runtime\RuntimeFactory::migrate();
$report = Pagou\Whmcs\Application\Runtime\RuntimeFactory::runtime()->runWorker('pagou-cli-' . getmypid());
$cardReport = Pagou\Whmcs\Application\Runtime\RuntimeFactory::cardReconciliation()->run();
fwrite(STDOUT, json_encode([
    'claimed' => $report->claimed,
    'succeeded' => $report->succeeded,
    'retried' => $report->retried,
    'uncertain' => $report->uncertain,
    'failed' => $report->failed,
    'card_inspected' => $cardReport['inspected'],
    'card_updated' => $cardReport['updated'],
    'card_applied' => $cardReport['applied'],
    'card_findings' => $cardReport['findings'],
    'card_failed' => $cardReport['failed'],
], JSON_THROW_ON_ERROR) . PHP_EOL);
