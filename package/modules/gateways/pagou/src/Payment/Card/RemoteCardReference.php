<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card;

final class RemoteCardReference
{
    public function __construct(
        public readonly string $customerId,
        public readonly string $cardId,
    ) {
        self::assertUuid($customerId);
        self::assertUuid($cardId);
    }

    public function encode(): string
    {
        $json = json_encode(
            ['v' => 1, 'customer_id' => $this->customerId, 'card_id' => $this->cardId],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(string $value): self
    {
        $value = trim($value);
        if (str_contains($value, '|')) {
            [$customerId, $cardId] = explode('|', $value, 2);

            return new self($customerId, $cardId);
        }
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $json = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($json)) {
            throw new \InvalidArgumentException('A referência remota do cartão é inválida.');
        }
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('A referência remota do cartão é inválida.', 0, $exception);
        }
        if (!is_array($decoded) || ($decoded['v'] ?? null) !== 1) {
            throw new \InvalidArgumentException('A versão da referência remota não é suportada.');
        }

        return new self((string) ($decoded['customer_id'] ?? ''), (string) ($decoded['card_id'] ?? ''));
    }

    private static function assertUuid(string $value): void
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value) !== 1) {
            throw new \InvalidArgumentException('A referência remota do cartão é inválida.');
        }
    }
}
