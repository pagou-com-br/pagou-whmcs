<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\Dto;

use InvalidArgumentException;
use Pagou\Whmcs\Payment\Card\CardStatus;

final class CardMapper
{
    /** @param array<string,mixed> $payload */
    public static function customer(array $payload): CardCustomer
    {
        return new CardCustomer(self::id($payload), self::nullableString($payload['name'] ?? null), self::nullableString($payload['email'] ?? null), self::array($payload['metadata'] ?? []));
    }

    /** @param array<string,mixed> $payload */
    public static function storedCard(array $payload): StoredCard
    {
        $brand = is_array($payload['brand'] ?? null)
            ? self::nullableString($payload['brand']['name'] ?? null)
            : self::nullableString($payload['brand'] ?? null);
        $expiresAt = self::nullableString($payload['expires_at'] ?? null);
        $expiryMonth = null;
        $expiryYear = null;
        if ($expiresAt !== null && preg_match('/^(\d{4})-(\d{2})$/', $expiresAt, $matches) === 1) {
            $expiryMonth = (int) $matches[2];
            $expiryYear = (int) $matches[1];
        } elseif ($expiresAt !== null && preg_match('/^(\d{2})\/(\d{2}|\d{4})$/', $expiresAt, $matches) === 1) {
            $expiryMonth = (int) $matches[1];
            $expiryYear = (int) $matches[2];
        }
        return new StoredCard(
            self::id($payload),
            self::string($payload['customer_id'] ?? $payload['customerId'] ?? ''),
            $brand,
            self::nullableString($payload['last_four'] ?? $payload['last4'] ?? null),
            self::nullableInt($payload['expiry_month'] ?? $payload['exp_month'] ?? $expiryMonth),
            self::nullableInt($payload['expiry_year'] ?? $payload['exp_year'] ?? $expiryYear),
            (bool) ($payload['active'] ?? true),
        );
    }

    /** @param array<string,mixed> $payload */
    public static function charge(array $payload): CardCharge
    {
        $source = isset($payload['charge']) && is_array($payload['charge']) ? $payload['charge'] : $payload;
        $amount = $source['amount_centavos'] ?? $source['amount'] ?? $source['value'] ?? 0;
        if (is_string($amount) && preg_match('/^-?\d+\.\d{2}$/', $amount)) {
            $amount = (int) round((float) $amount * 100);
        }
        if (!is_int($amount) && !ctype_digit((string) $amount)) {
            throw new InvalidArgumentException('Card amount is invalid.');
        }
        $providerStatus = self::nullableString($source['status'] ?? $source['payment_status'] ?? null);
        return new CardCharge(
            self::id($source),
            CardStatus::fromProvider($providerStatus),
            (int) $amount,
            self::nullableString($source['customer_id'] ?? $source['customerId'] ?? null),
            self::nullableString($source['card_id'] ?? $source['cardId'] ?? $source['card_reference'] ?? null),
            $providerStatus,
            self::nullableString($source['three_ds_url'] ?? $source['threeDSUrl'] ?? $source['redirect_url'] ?? null),
            $source,
        );
    }

    /** @return array<string, mixed> */
    public static function chargeRequest(CardChargeRequest $payload): array
    {
        $request = [
            'value' => $payload->amountCentavos,
            'customer_id' => $payload->customerId,
            'card_id' => $payload->cardReference,
            'installments' => $payload->installments,
            'installments_capture' => $payload->capture,
            'description' => mb_substr($payload->merchantReference, 0, 255),
            'payday' => $payload->payday ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d'),
        ];
        if ($payload->softDescriptor !== null) {
            $request['soft_descriptor'] = $payload->softDescriptor;
        }
        if (isset($payload->metadata['three_ds']) && is_array($payload->metadata['three_ds'])) {
            $threeDs = self::threeDs($payload->metadata['three_ds']);
            if ($threeDs !== []) {
                $request['three_ds'] = $threeDs;
            }
        }
        if (isset($payload->metadata['antifraud']) && is_array($payload->metadata['antifraud'])) {
            $request['antifraud'] = self::scalarMap($payload->metadata['antifraud']);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function threeDs(array $value): array
    {
        $internal = $value['internal_emv'] ?? null;
        if (!is_array($internal)) {
            return [];
        }
        $device = is_array($internal['device'] ?? null) ? $internal['device'] : [];
        $result = [
            'internal_emv' => [
                'ip' => self::nullableString($internal['ip'] ?? null),
                'user_agent' => self::nullableString($internal['user_agent'] ?? null),
                'redirect_url_3ds' => self::nullableString($internal['redirect_url_3ds'] ?? null),
                'device' => [
                    'color_depth' => self::nullableDigitString($device['color_depth'] ?? null),
                    'device_type_3ds' => self::nullableString($device['device_type_3ds'] ?? null),
                    'java_enabled' => (bool) ($device['java_enabled'] ?? false),
                    'language' => self::nullableString($device['language'] ?? null),
                    'screen_height' => self::nullableDigitString($device['screen_height'] ?? null),
                    'screen_width' => self::nullableDigitString($device['screen_width'] ?? null),
                    'time_zone_offset' => self::nullableSignedDigitString($device['time_zone_offset'] ?? null),
                ],
            ],
        ];

        return self::removeNulls($result);
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, scalar|null>
     */
    private static function scalarMap(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (preg_match('/^[a-z0-9_]{1,64}$/', $key) === 1 && (is_scalar($item) || $item === null)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function removeNulls(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $item = self::removeNulls($item);
            }
            if ($item === null || $item === []) {
                unset($value[$key]);
            } else {
                $value[$key] = $item;
            }
        }

        return $value;
    }

    /** @param array<string,mixed> $payload */
    private static function id(array $payload): string
    {
        return self::string($payload['id'] ?? $payload['uuid'] ?? '');
    }
    private static function string(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Provider response lacks an identifier.');
        } return $value;
    }
    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
    private static function nullableInt(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
    private static function nullableDigitString(mixed $value): ?string
    {
        $value = is_int($value) ? (string) $value : $value;

        return is_string($value) && preg_match('/^\d{1,8}$/', $value) === 1 ? $value : null;
    }
    private static function nullableSignedDigitString(mixed $value): ?string
    {
        $value = is_int($value) ? (string) $value : $value;

        return is_string($value) && preg_match('/^-?\d{1,8}$/', $value) === 1 ? $value : null;
    }
    /** @return array<string,mixed> */ private static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
