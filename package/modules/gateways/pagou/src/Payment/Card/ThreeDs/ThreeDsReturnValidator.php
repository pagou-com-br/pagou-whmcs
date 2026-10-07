<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\ThreeDs;

use InvalidArgumentException;

/** Validates that a browser return belongs to the same local payment attempt. */
final class ThreeDsReturnValidator
{
    public function __construct(private readonly string $returnSecret)
    {
        if (strlen($returnSecret) < 32) {
            throw new InvalidArgumentException('3DS return secret must contain at least 32 bytes.');
        }
    }

    public function sign(string $chargeId, string $attemptId): string
    {
        return hash_hmac('sha256', $chargeId . ':' . $attemptId, $this->returnSecret);
    }

    public function isValid(string $chargeId, string $attemptId, string $signature): bool
    {
        return hash_equals($this->sign($chargeId, $attemptId), $signature);
    }
}
