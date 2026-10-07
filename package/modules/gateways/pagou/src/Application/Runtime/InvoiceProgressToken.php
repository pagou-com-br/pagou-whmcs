<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

/** Session-bound CSRF token for advancing an already authorized invoice. */
final class InvoiceProgressToken
{
    public static function issue(int $invoiceId): string
    {
        $tokens = $_SESSION['pagou_invoice_progress'] ?? [];
        if (!is_array($tokens)) {
            $tokens = [];
        }
        $token = is_string($tokens[$invoiceId] ?? null) ? $tokens[$invoiceId] : bin2hex(random_bytes(24));
        unset($tokens[$invoiceId]);
        $tokens[$invoiceId] = $token;
        $_SESSION['pagou_invoice_progress'] = array_slice($tokens, -50, null, true);
        return $token;
    }

    public static function valid(int $invoiceId, mixed $token): bool
    {
        $expected = $_SESSION['pagou_invoice_progress'][$invoiceId] ?? null;
        return is_string($token) && is_string($expected) && hash_equals($expected, $token);
    }
}
