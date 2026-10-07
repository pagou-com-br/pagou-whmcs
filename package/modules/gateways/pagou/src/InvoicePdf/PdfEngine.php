<?php

declare(strict_types=1);

namespace Pagou\Whmcs\InvoicePdf;

use Pagou\Whmcs\Vendor\Fpdi\Tcpdf\Fpdi;

/** Never let TCPDF's optional die() error policy abort a WHMCS request. */
final class PdfEngine extends Fpdi
{
    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->tcpdflink = false;
    }

    /** @param string $msg */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- TCPDF public API override.
    public function Error($msg): never
    {
        throw new \RuntimeException('Não foi possível renderizar o PDF de pagamento.');
    }
}
