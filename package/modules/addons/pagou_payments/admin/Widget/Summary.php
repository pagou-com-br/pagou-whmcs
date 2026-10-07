<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\Widget;

use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

/** Validate the account receipt contract before displaying any financial totals. */
final class Summary
{
    /** @param array<string, mixed> $payload
     *  @return array<string, int|string>
     */
    public static function fromPayload(array $payload, DateTimeImmutable $now): array
    {
        if (
            ($payload['version'] ?? null) !== 1 || ($payload['currency'] ?? null) !== 'BRL'
            || ($payload['timezone'] ?? null) !== 'America/Sao_Paulo'
            || ($payload['basis'] ?? null) !== 'gross_payment_credits'
            || ($payload['sources'] ?? null) !== ['pix', 'charge', 'creditcard_settlement']
        ) {
            throw new UnexpectedValueException('Contrato de recebimentos inválido.');
        }
        $asOf = self::date($payload['as_of'] ?? null);
        $zone = new DateTimeZone('America/Sao_Paulo');
        $today = $asOf->setTimezone($zone)->setTime(0, 0);
        if (
            $today->format('Y-m-d') !== $now->setTimezone($zone)->format('Y-m-d')
            || $asOf->getTimestamp() > $now->getTimestamp() + 60
            || $asOf->getTimestamp() < $now->getTimestamp() - 300
        ) {
            throw new UnexpectedValueException('Consulta de recebimentos fora do período.');
        }
        $month = $today->modify('first day of this month');
        $result = ['as_of' => $asOf->getTimestamp(), 'day' => $today->format('Y-m-d'), 'month' => $month->format('Y-m-d')];
        foreach (['today' => $today, 'month' => $month] as $key => $start) {
            $period = $payload[$key] ?? null;
            if (
                !is_array($period) || self::date($period['from'] ?? null) != $start
                || self::date($period['until'] ?? null) != $asOf
            ) {
                throw new UnexpectedValueException('Período de recebimentos inválido.');
            }
            $result[$key . '_amount'] = self::integer($period['amount_cents'] ?? null);
            $result[$key . '_count'] = self::integer($period['receipt_count'] ?? null);
            if ($result[$key . '_count'] === 0 && $result[$key . '_amount'] !== 0) {
                throw new UnexpectedValueException('Total de recebimentos inconsistente.');
            }
        }
        if ($result['today_amount'] > $result['month_amount'] || $result['today_count'] > $result['month_count']) {
            throw new UnexpectedValueException('Total de recebimentos inconsistente.');
        }
        return $result;
    }

    private static function integer(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > 9007199254740991) {
            throw new UnexpectedValueException('Valor de recebimentos inválido.');
        }
        return $value;
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/D', $value) !== 1) {
            throw new UnexpectedValueException('Data de recebimentos inválida.');
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception $error) {
            throw new UnexpectedValueException('Data de recebimentos inválida.', 0, $error);
        }
        $canonical = str_contains($value, '.') ? $date->format('Y-m-d\TH:i:s.v\Z') : $date->format('Y-m-d\TH:i:s\Z');
        if ($canonical !== $value) {
            throw new UnexpectedValueException('Data de recebimentos inválida.');
        }
        return $date;
    }
}
