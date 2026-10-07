<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;
use Pagou\Whmcs\Payment\Pix\Infrastructure\PdoPixRefundStore;

final class PixRefundView
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $attempt
     * @return array<string,mixed>
     */
    public function forAttempt(array $attempt): array
    {
        $row = (new PdoPixRefundStore($this->pdo))->find((string) $attempt['id']);
        if ($row !== null) {
            $partial = (int) $row['amount_cents'] < (int) $row['receipt_cents'];
            return ['status' => $row['status'], 'amount' => number_format((int) $row['amount_cents'] / 100, 2, ',', '.'),
                'partial' => $partial, 'providerId' => $row['provider_refund_id'] ?? '',
                'state' => $row['status'] === 'applied' ? ($partial ? 'partially_refunded' : 'refunded') : ($row['status'] === 'rejected' ? 'paid' : 'refund_pending')];
        }
        return [];
    }
}
