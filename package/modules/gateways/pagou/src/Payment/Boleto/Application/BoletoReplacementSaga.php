<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Application;

use Pagou\Whmcs\Payment\Boleto\Contracts\BoletoAttemptRepository;
use Pagou\Whmcs\Payment\Boleto\Contracts\JobQueue;
use Pagou\Whmcs\Payment\Boleto\Api\BoletoClient;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;

/**
 * A replacement is a persisted two-step saga: cancel the old charge, then enqueue a new one.
 * The invoice never replaces a charge inline, and a failed cancel never creates the replacement.
 */
final class BoletoReplacementSaga
{
    public function __construct(
        private readonly BoletoAttemptRepository $attempts,
        private readonly BoletoCancellationHandler $cancellation,
        private readonly BoletoClient $client,
        private readonly JobQueue $queue,
    ) {
    }

    public function begin(string $previousAttemptId, BoletoAttempt $replacement): BoletoAttempt
    {
        $previous = $this->attempts->get($previousAttemptId);
        if ($previous === null || $previous->invoiceId !== $replacement->invoiceId) {
            throw new \LogicException('Replacement must belong to the same invoice.');
        }
        if ($replacement->supersedesAttemptId !== $previous->id || $replacement->revision <= $previous->revision) {
            throw new \LogicException('Replacement must explicitly supersede a prior boleto revision.');
        }

        $previous = $this->cancellation->request($previous->id);
        $this->attempts->save($replacement);
        $this->queue->enqueue('boleto.replacement.refresh', [
            'previous_attempt_id' => $previous->id,
            'replacement_attempt_id' => $replacement->id,
        ], 'boleto:replacement:' . $previous->id . ':' . $replacement->id);
        return $replacement;
    }

    public function resume(string $previousAttemptId, string $replacementAttemptId): BoletoAttempt
    {
        $previous = $this->attempts->get($previousAttemptId);
        $replacement = $this->attempts->get($replacementAttemptId);
        if ($previous === null || $replacement === null || $previous->remoteId === null) {
            throw new \LogicException('Replacement saga state is missing.');
        }
        if ($previous->status === BoletoAttempt::SUPERSEDED) {
            return $replacement;
        }
        $charge = $this->client->get($previous->remoteId);
        if (!in_array(strtolower($charge->status), ['cancelled', 'canceled', 'voided'], true)) {
            $this->queue->enqueue('boleto.replacement.refresh', [
                'previous_attempt_id' => $previous->id,
                'replacement_attempt_id' => $replacement->id,
            ], 'boleto:replacement:' . $previous->id . ':' . $replacement->id);
            return $replacement;
        }
        $this->attempts->save($previous->cancelled()->supersededBy($replacement->id));
        $this->queue->enqueue('boleto.emit', ['attempt_id' => $replacement->id], 'boleto:emit:' . $replacement->id);
        return $replacement;
    }
}
