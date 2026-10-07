<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\Widget;

use DateTimeImmutable;
use DateTimeZone;
use Pagou\Whmcs\Domain\Money;

/** Session-owned cache: no financial writes or cross-user cached HTML. */
final class Snapshot
{
    /**
     * @param array<string, mixed> $cache
     * @param callable():array<string, mixed> $balance
     * @param callable():array<string, int|string> $summary
     * @param null|callable():array<string, int> $findings
     * @return array<string, mixed>
     */
    public function read(array &$cache, string $identity, callable $balance, callable $summary, DateTimeImmutable $now, bool $force = false, bool $fetchRemote = true, ?callable $findings = null): array
    {
        if (($cache['identity'] ?? '') !== $identity || ($cache['schema'] ?? null) !== 2) {
            $cache = ['identity' => $identity, 'schema' => 2];
        }
        $stamp = $now->getTimestamp();
        $day = $now->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d');
        foreach (['balance', 'summary', 'findings'] as $part) {
            $state = is_array($cache[$part] ?? null) ? $cache[$part] : [];
            $expired = $stamp >= (int) ($state['retryAt'] ?? 0);
            $newDay = $part === 'summary' && ($state['day'] ?? '') !== $day;
            // Never carry yesterday's totals into a new day, even during deferred refresh.
            if ($newDay) {
                $state = [];
            }
            if ($force || $expired || $newDay) {
                if ($part !== 'findings' && !$fetchRemote) {
                    $cache[$part] = array_replace($state, ['pending' => true]);
                    continue;
                }
                unset($state['pending']);
                try {
                    $value = match ($part) {
                        'balance' => self::balance($balance()),
                        'summary' => $summary(),
                        default => $findings !== null ? $findings() : throw new \RuntimeException('Pendências indisponíveis.'),
                    };
                    $asOf = $part === 'summary' ? (int) ($value['as_of'] ?? $stamp) : $stamp;
                    $state = ['value' => $value, 'asOf' => $asOf, 'error' => false, 'day' => $day, 'retryAt' => $stamp + 120];
                } catch (\Throwable) {
                    $state['error'] = true;
                    $state['day'] = $day;
                    $state['retryAt'] = $stamp + 30;
                }
                $cache[$part] = $state;
            }
        }

        return ['balance' => $cache['balance'], 'summary' => $cache['summary'], 'findings' => $cache['findings']];
    }

    /** @param array<string, mixed> $payload
     *  @return array{available:int,held:int}
     */
    public static function balance(array $payload): array
    {
        return ['available' => self::amount($payload['balance'] ?? null), 'held' => self::amount($payload['hold'] ?? 0)];
    }

    private static function amount(mixed $value): int
    {
        if (
            (!is_int($value) && !is_float($value) && !is_string($value))
            || preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', (string) $value) !== 1
        ) {
            throw new \UnexpectedValueException('Saldo inválido.');
        }

        return Money::fromDecimal((string) $value)->centavos();
    }
}
