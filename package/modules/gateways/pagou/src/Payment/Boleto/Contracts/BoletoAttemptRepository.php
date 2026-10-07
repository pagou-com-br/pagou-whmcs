<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Contracts;

use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;

interface BoletoAttemptRepository
{
    public function get(string $attemptId): ?BoletoAttempt;

    public function save(BoletoAttempt $attempt): void;
}
