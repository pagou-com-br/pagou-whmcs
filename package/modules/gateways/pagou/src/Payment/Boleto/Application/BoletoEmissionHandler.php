<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Application;

use Pagou\Whmcs\Payment\Boleto\Api\BoletoClient;
use Pagou\Whmcs\Payment\Boleto\Api\CreateBoletoRequest;
use Pagou\Whmcs\Payment\Boleto\Contracts\BoletoAttemptRepository;
use Pagou\Whmcs\Payment\Boleto\Contracts\JobQueue;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;

/** Worker entry point. Invoice creation only queues this work and never calls it inline. */
final class BoletoEmissionHandler
{
    public function __construct(
        private readonly BoletoAttemptRepository $attempts,
        private readonly BoletoClient $client,
        private readonly JobQueue $queue,
    ) {
    }

    public function handle(string $attemptId, CreateBoletoRequest $request): BoletoAttempt
    {
        $attempt = $this->attempts->get($attemptId);
        if ($attempt === null) {
            throw new \OutOfBoundsException('Boleto attempt not found.');
        }
        if ($attempt->status !== BoletoAttempt::QUEUED) {
            return $attempt;
        }

        $charge = $this->client->create($request);
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
