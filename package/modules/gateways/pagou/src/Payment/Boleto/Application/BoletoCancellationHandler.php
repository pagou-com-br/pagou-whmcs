<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Application;

use Pagou\Whmcs\Payment\Boleto\Api\BoletoClient;
use Pagou\Whmcs\Payment\Boleto\Contracts\BoletoAttemptRepository;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;

final class BoletoCancellationHandler
{
    public function __construct(private readonly BoletoAttemptRepository $attempts, private readonly BoletoClient $client)
    {
    }

    public function request(string $attemptId): BoletoAttempt
    {
        $attempt = $this->attempts->get($attemptId);
        if ($attempt === null || $attempt->remoteId === null) {
            throw new \LogicException('Boleto attempt cannot be cancelled before remote creation.');
        }
        $remoteId = $attempt->remoteId;
        if (!in_array($attempt->status, [BoletoAttempt::READY, BoletoAttempt::AWAITING_REGISTRATION], true)) {
            throw new \LogicException('Boleto attempt is not cancellable in its current state.');
        }
        $attempt = $attempt->cancellationRequested();
        $this->attempts->save($attempt);

        $this->client->cancel($remoteId);
        return $attempt;
    }
}
