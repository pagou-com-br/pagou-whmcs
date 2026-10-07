<?php

declare(strict_types=1);

namespace Pagou\Whmcs\InvoicePdf;

use Pagou\Whmcs\Payment\Pix\Dto\PixCharge;

final class PixValidity
{
    public static function until(PixCharge $charge, string $attemptCreatedAt): string
    {
        if ($charge->expiresAt !== null && $charge->expiresAt !== '') {
            return $charge->expiresAt;
        }
        $expiration = $charge->raw['expiration'] ?? null;
        if (!is_numeric($expiration) || (int) $expiration < 1) {
            return '';
        }
        $due = $charge->dueDate ?? ($charge->raw['due_date'] ?? null);
        try {
            if (is_string($due) && preg_match('/\A\d{4}-\d{2}-\d{2}/', $due) === 1 && (int) $expiration <= 365) {
                $date = new \DateTimeImmutable(substr($due, 0, 10), new \DateTimeZone('America/Sao_Paulo'));
                return $date->modify('+' . (int) $expiration . ' days')->setTime(23, 59, 59)->format(DATE_ATOM);
            }
            if ($due === null && $attemptCreatedAt !== '' && (int) $expiration <= 2592000) {
                return (new \DateTimeImmutable($attemptCreatedAt, new \DateTimeZone('UTC')))
                    ->modify('+' . (int) $expiration . ' seconds')->format(DATE_ATOM);
            }
        } catch (\Exception) {
            return '';
        }
        return '';
    }
}
