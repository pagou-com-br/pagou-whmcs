<?php

declare(strict_types=1);

namespace Pagou\Whmcs\InvoicePdf;

use Pagou\Whmcs\Application\Runtime\RuntimeFactory;

final class TemplateBridge
{
    public static function replace(mixed &$pdf, int $invoiceId, string $gateway): bool
    {
        if (
            !$pdf instanceof \TCPDF || !defined('ROOTDIR')
            || !in_array($gateway, ['pagou_pix', 'pagou_boleto'], true)
        ) {
            return false;
        }
        try {
            $selector = new DocumentSelector(
                RuntimeFactory::pdo(),
                IntegrationService::storage((string) constant('ROOTDIR')),
                RuntimeFactory::localApi(),
            );
            $document = $selector->select($invoiceId, $gateway);
            if ($document === null) {
                return false;
            }
            $replacement = (new DocumentRenderer())->render($document);
            $pdf = $replacement;
            return true;
        } catch (\Throwable $exception) {
            // Keep payment payloads, paths and credentials out of WHMCS logs.
            if (function_exists('logActivity')) {
                logActivity('Pagou: PDF de pagamento indisponível para a fatura ' . $invoiceId . '. Mantido o PDF do tema.');
            }
            return false;
        }
    }
}
