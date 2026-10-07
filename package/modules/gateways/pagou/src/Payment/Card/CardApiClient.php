<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card;

use InvalidArgumentException;
use Pagou\Whmcs\Payment\Card\Dto\CardCharge;
use Pagou\Whmcs\Payment\Card\Dto\CardChargeRequest;
use Pagou\Whmcs\Payment\Card\Dto\CardCustomer;
use Pagou\Whmcs\Payment\Card\Dto\CardMapper;
use Pagou\Whmcs\Payment\Card\Dto\StoredCard;

/**
 * Thin adapter over the current Pagou credit-card contract.
 * Every mutation carries a stable Idempotency-Key supplied by the caller.
 */
final class CardApiClient
{
    public function __construct(private readonly CardTransport $transport)
    {
    }

    /** @param array<string,mixed> $details */
    public function createCustomer(array $details, IdempotencyKey $key): CardCustomer
    {
        $this->assertNoSensitiveCardData($details);
        return CardMapper::customer($this->request('POST', '/v1/creditcard/customers', $key, $details));
    }

    /** @param array<string,mixed> $tokenPayload */
    public function createCard(string $customerId, array $tokenPayload, IdempotencyKey $key): StoredCard
    {
        $this->assertNoSensitiveCardData($tokenPayload);
        if (!isset($tokenPayload['token']) && !isset($tokenPayload['card_token']) && !isset($tokenPayload['card_id'])) {
            throw new InvalidArgumentException('A provider-issued card token is required.');
        }
        if (isset($tokenPayload['token']) && !isset($tokenPayload['card_token'])) {
            $tokenPayload['card_token'] = $tokenPayload['token'];
            unset($tokenPayload['token']);
        }
        $tokenPayload['customer_id'] = $customerId;
        return CardMapper::storedCard($this->request('POST', '/v1/creditcard/cards', $key, $tokenPayload));
    }

    public function createCharge(CardChargeRequest $charge, IdempotencyKey $key): CardCharge
    {
        return CardMapper::charge($this->request('POST', '/v1/creditcard/charges', $key, CardMapper::chargeRequest($charge)));
    }

    public function capture(string $chargeId, IdempotencyKey $key, ?int $amountCentavos = null): CardCharge
    {
        if ($amountCentavos !== null) {
            throw new InvalidArgumentException('The current Pagou contract only supports full capture.');
        }

        return CardMapper::charge($this->request('PUT', $this->chargePath($chargeId) . '/capture', $key, []));
    }

    public function reverse(string $chargeId, IdempotencyKey $key): CardCharge
    {
        return CardMapper::charge($this->request('PUT', $this->chargePath($chargeId) . '/reverse', $key, ['reason' => '']));
    }

    public function retry(string $chargeId, IdempotencyKey $key): CardCharge
    {
        return CardMapper::charge($this->request('PUT', $this->chargePath($chargeId) . '/retry', $key, []));
    }

    public function cancelPendingCharge(string $chargeId, IdempotencyKey $key): CardCharge
    {
        return CardMapper::charge($this->request('DELETE', $this->chargePath($chargeId), $key, []));
    }

    public function deleteCard(string $cardId, IdempotencyKey $key): void
    {
        $this->request('DELETE', '/v1/creditcard/cards/' . rawurlencode($cardId), $key, []);
    }

    public function getCard(string $cardId): StoredCard
    {
        return CardMapper::storedCard($this->request('GET', '/v1/creditcard/cards/' . rawurlencode($cardId)));
    }

    /** @return list<StoredCard> */
    public function listCards(?string $customerId = null): array
    {
        $path = '/v1/creditcard/cards' . ($customerId === null ? '' : '?customer_id=' . rawurlencode($customerId));
        $response = $this->request('GET', $path);
        $cards = $response['items'] ?? $response['data'] ?? $response['cards'] ?? $response;
        if (!is_array($cards)) {
            return [];
        }
        return array_values(array_map(static fn (mixed $card): StoredCard => CardMapper::storedCard(is_array($card) ? $card : []), $cards));
    }

    public function getCharge(string $chargeId): CardCharge
    {
        return CardMapper::charge($this->request('GET', $this->chargePath($chargeId)));
    }

    /** @return list<CardCharge> */
    public function listCharges(?string $customerId = null): array
    {
        $path = '/v1/creditcard/charges' . ($customerId === null ? '' : '?customer_id=' . rawurlencode($customerId));
        $response = $this->request('GET', $path);
        $charges = $response['items'] ?? $response['data'] ?? $response['charges'] ?? $response;
        if (!is_array($charges)) {
            return [];
        }
        return array_values(array_map(static fn (mixed $charge): CardCharge => CardMapper::charge(is_array($charge) ? $charge : []), $charges));
    }

    public function getCustomer(string $customerId): CardCustomer
    {
        return CardMapper::customer($this->request('GET', '/v1/creditcard/customers/' . rawurlencode($customerId)));
    }

    /** @return list<CardCustomer> */
    public function listCustomers(): array
    {
        $response = $this->request('GET', '/v1/creditcard/customers');
        $customers = $response['items'] ?? $response['data'] ?? $response['customers'] ?? $response;
        if (!is_array($customers)) {
            return [];
        }
        return array_values(array_map(static fn (mixed $customer): CardCustomer => CardMapper::customer(is_array($customer) ? $customer : []), $customers));
    }

    /** A subsequent recurrence is always a new charge using only a saved opaque reference. */
    public function chargeRecurring(CardChargeRequest $charge, IdempotencyKey $key): CardCharge
    {
        return $this->createCharge($charge, $key);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?IdempotencyKey $key = null, ?array $payload = null): array
    {
        $headers = $key === null ? [] : ['Idempotency-Key' => $key->value()];
        return $this->transport->request($method, $path, $headers, $payload);
    }

    private function chargePath(string $chargeId): string
    {
        if (trim($chargeId) === '') {
            throw new InvalidArgumentException('Charge identifier is required.');
        }
        return '/v1/creditcard/charges/' . rawurlencode($chargeId);
    }

    /** @param array<string,mixed> $payload */
    private function assertNoSensitiveCardData(array $payload): void
    {
        foreach (['pan', 'card_number', 'number', 'cvv', 'cvc', 'security_code', 'expiry_month', 'expiry_year'] as $key) {
            if (array_key_exists($key, $payload)) {
                throw new InvalidArgumentException('Raw card data must never be sent through the module backend.');
            }
        }
    }
}
