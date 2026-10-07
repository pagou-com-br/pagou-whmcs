<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

/**
 * Names intentionally describe a business operation, never an HTTP endpoint.
 */
enum OperationType: string
{
    case IssuePix = 'issue_pix';
    case CancelPix = 'cancel_pix';
    case IssueBoleto = 'issue_boleto';
    case FetchBoletoPdf = 'fetch_boleto_pdf';
    case DeliverInvoiceEmail = 'deliver_invoice_email';
    case CancelBoleto = 'cancel_boleto';
    case ReplaceBoleto = 'replace_boleto';
    case ReconcilePayment = 'reconcile_payment';
    case ReconcileUncertainOperation = 'reconcile_uncertain_operation';
}
