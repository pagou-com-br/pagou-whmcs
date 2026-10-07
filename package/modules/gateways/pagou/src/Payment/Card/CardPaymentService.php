<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card;

use Pagou\Whmcs\Payment\Card\Contracts\CardOperationJournal;
use Pagou\Whmcs\Payment\Card\Contracts\CardReconciliationScheduler;
use Pagou\Whmcs\Payment\Card\Dto\CardCharge;
use Pagou\Whmcs\Payment\Card\Dto\CardChargeRequest;
use Pagou\Whmcs\Payment\Card\Dto\CardCustomer;
use Pagou\Whmcs\Payment\Card\Dto\StoredCard;
use Pagou\Whmcs\Payment\Card\Exception\CardUncertainOperation;
use Pagou\Whmcs\Infrastructure\Http\ApiException;

/**
 * Application boundary for all card mutations. A remote failure after dispatch
 * is always journaled as uncertain and queued for reconciliation, never retried
 * blindly by this request.
 */
final class CardPaymentService
{
    public function __construct(
        private readonly CardApiClient $api,
        private readonly CardReconciler $reconciler,
        private readonly CardOperationJournal $journal,
        private readonly CardReconciliationScheduler $reconciliation,
    ) {
    }

    /** @param array<string,mixed> $details */
    public function createCustomer(array $details, IdempotencyKey $key): CardCustomer
    {
        return $this->mutate('card.customer.create', $key, [], fn (): CardCustomer => $this->api->createCustomer($details, $key));
    }

    /** @param array<string,mixed> $tokenResult */
    public function registerOpaqueCard(string $customerId, array $tokenResult, IdempotencyKey $key): StoredCard
    {
        $card = $this->mutate('card.card.create', $key, ['customer_id' => $customerId], fn (): StoredCard => $this->api->createCard($customerId, $tokenResult, $key));
        if ($card->customerId !== $customerId) {
            throw new CardUncertainOperation('O cartão retornado não corresponde ao cliente informado.');
        }
        return $card;
    }

    public function charge(CardChargeRequest $request, IdempotencyKey $key): CardCharge
    {
        CardCheckoutPolicy::assertSupported($request->installments, $request->capture);
        $charge = $this->mutate('card.charge.create', $key, ['customer_id' => $request->customerId, 'reference' => $request->merchantReference, 'amount_centavos' => $request->amountCentavos], fn (): CardCharge => $this->api->createCharge($request, $key));
        if (
            $charge->amountCentavos !== $request->amountCentavos
            || ($charge->customerId !== null && $charge->customerId !== $request->customerId)
            || ($charge->cardId !== null && $charge->cardId !== $request->cardReference)
        ) {
            $this->reconciliation->schedule('card.charge.create', $key->value(), ['charge_id' => $charge->id]);
            throw new CardUncertainOperation('Os dados retornados pela Pagou exigem conciliação.');
        }
        return $charge;
    }

    public function chargeRecurring(CardChargeRequest $request, IdempotencyKey $key): CardCharge
    {
        CardCheckoutPolicy::assertSupported($request->installments, $request->capture);
        $charge = $this->mutate('card.charge.recurring', $key, ['customer_id' => $request->customerId, 'card_reference' => $request->cardReference, 'reference' => $request->merchantReference], fn (): CardCharge => $this->api->chargeRecurring($request, $key));
        if (
            $charge->amountCentavos !== $request->amountCentavos
            || ($charge->customerId !== null && $charge->customerId !== $request->customerId)
            || ($charge->cardId !== null && $charge->cardId !== $request->cardReference)
        ) {
            $this->reconciliation->schedule('card.charge.recurring', $key->value(), ['charge_id' => $charge->id]);
            throw new CardUncertainOperation('Os dados retornados pela Pagou exigem conciliação.');
        }
        return $charge;
    }

    public function capture(string $chargeId, IdempotencyKey $key, ?int $amountCentavos = null): CardCharge
    {
        throw new \LogicException('Captura posterior não está disponível nesta versão do módulo.');
    }

    /** Reverse an authorization before it settles. */
    public function reverse(string $chargeId, IdempotencyKey $key): CardCharge
    {
        return $this->mutate('card.charge.reverse', $key, ['charge_id' => $chargeId], fn (): CardCharge => $this->api->reverse($chargeId, $key));
    }

    /** Refund is represented by the provider reverse operation for the current contract. */
    public function refund(string $chargeId, IdempotencyKey $key): CardCharge
    {
        return $this->mutate('card.charge.refund', $key, ['charge_id' => $chargeId], fn (): CardCharge => $this->api->reverse($chargeId, $key));
    }

    public function retry(string $chargeId, IdempotencyKey $key): CardCharge
    {
        return $this->mutate('card.charge.retry', $key, ['charge_id' => $chargeId], fn (): CardCharge => $this->api->retry($chargeId, $key));
    }

    public function cancelPending(string $chargeId, IdempotencyKey $key): CardCharge
    {
        return $this->mutate('card.charge.cancel', $key, ['charge_id' => $chargeId], fn (): CardCharge => $this->api->cancelPendingCharge($chargeId, $key));
    }

    public function deleteCard(string $cardId, IdempotencyKey $key): void
    {
        $operation = 'card.card.delete';
        $context = ['card_id' => $cardId];
        $idempotencyKey = $key->value();
        $this->journal->started($operation, $idempotencyKey, $context);
        try {
            $this->api->deleteCard($cardId, $key);
            $this->journal->succeeded($operation, $idempotencyKey, ['remote_id' => $cardId]);
        } catch (\Throwable $exception) {
            $context['reason'] = $exception::class;
            if ($exception instanceof ApiException && $this->isConclusive($exception)) {
                if ($exception->statusCode === 404) {
                    $this->journal->succeeded($operation, $idempotencyKey, ['remote_id' => $cardId]);
                    return;
                }
                $this->journal->failed($operation, $idempotencyKey, $context);
                throw $exception;
            }
            $this->journal->uncertain($operation, $idempotencyKey, $context);
            $this->reconciliation->schedule($operation, $idempotencyKey, $context);
            throw new CardUncertainOperation('The card deletion outcome is unknown and was queued for reconciliation.', 0, $exception);
        }
    }

    public function getCharge(string $chargeId): CardCharge
    {
        return $this->api->getCharge($chargeId);
    }

    public function getCard(string $cardId): StoredCard
    {
        return $this->api->getCard($cardId);
    }

    public function reconcile(string $chargeId): CardCharge
    {
        return $this->reconciler->reconcile($chargeId);
    }

    /**
     * @template T of CardCharge|CardCustomer|StoredCard
     * @param array<string, mixed> $context
     * @param callable(): T $call
     * @return T
     */
    private function mutate(string $operation, IdempotencyKey $key, array $context, callable $call): CardCharge|CardCustomer|StoredCard
    {
        $idempotencyKey = $key->value();
        $this->journal->started($operation, $idempotencyKey, $context);
        try {
            $result = $call();
            $this->journal->succeeded($operation, $idempotencyKey, ['remote_id' => $result->id]);
            return $result;
        } catch (\Throwable $exception) {
            $context['reason'] = $exception::class;
            if ($exception instanceof ApiException && $this->isConclusive($exception)) {
                $context['reason'] = $exception->kind;
                $this->journal->failed($operation, $idempotencyKey, $context);
                throw $exception;
            }
            $this->journal->uncertain($operation, $idempotencyKey, $context);
            $this->reconciliation->schedule($operation, $idempotencyKey, $context);
            throw new CardUncertainOperation('The card operation outcome is unknown and was queued for reconciliation.', 0, $exception);
        }
    }

    private function isConclusive(ApiException $exception): bool
    {
        return $exception->isConclusiveRejection();
    }
}
