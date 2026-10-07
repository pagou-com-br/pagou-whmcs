<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Application;

use Pagou\Whmcs\Payment\Boleto\Api\BoletoClient;
use Pagou\Whmcs\Payment\Boleto\Contracts\BoletoAttemptRepository;
use Pagou\Whmcs\Payment\Boleto\Contracts\JobQueue;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;

final class BoletoRefreshHandler
{
    public function __construct(private readonly BoletoAttemptRepository $attempts, private readonly BoletoClient $client, private readonly JobQueue $queue)
    {
    }

    public function handle(string $attemptId): BoletoAttempt
    {
        $attempt = $this->attempts->get($attemptId);
        if ($attempt === null || $attempt->remoteId === null || $attempt->status !== BoletoAttempt::AWAITING_REGISTRATION) {
            throw new \LogicException('Boleto attempt is not awaiting registration.');
        }
        $charge = $this->client->get($attempt->remoteId);
        $attempt = $attempt->issued($charge);
        $this->attempts->save($attempt);

        if ($attempt->status === BoletoAttempt::AWAITING_REGISTRATION) {
            $this->queue->enqueue('boleto.refresh', ['attempt_id' => $attempt->id], 'boleto:refresh:' . $attempt->id);
        } elseif ($attempt->artifacts?->pdfUrl !== null) {
            $this->queue->enqueue('boleto.cache_pdf', ['attempt_id' => $attempt->id], 'boleto:pdf:' . $attempt->id);
        }
        return $attempt;
    }
}
