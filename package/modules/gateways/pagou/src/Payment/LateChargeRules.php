<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment;

/** The API owns calculation; these rules validate the instructions sent to it. */
final class LateChargeRules
{
    /** @return array<string, string> */
    public static function interestOptions(): array
    {
        return [
            'none' => 'Não aplicar',
            'fixed' => 'Valor em reais por dia corrido',
            'percentage_calendar_days' => 'Percentual ao dia (dias corridos)',
            'percentage_month_calendar_days' => 'Percentual ao mês (dias corridos)',
        ];
    }

    /** @param array<string, mixed> $settings */
    public static function needsReview(array $settings, string $method): bool
    {
        if ($method === 'pix') {
            return (float) str_replace(',', '.', (string) ($settings['pix_due_interest_amount'] ?? '0')) > 0
                && ($settings['pix_due_interest_type'] ?? 'none') !== 'none'
                && (($settings['pix_late_charges_reviewed'] ?? '0') !== '1'
                    || ($settings['pix_due_interest_type'] ?? '') === 'percentage');
        }

        return $method === 'boleto' && ($settings['boleto_late_charges_reviewed'] ?? '0') !== '1'
            && ((float) str_replace(',', '.', (string) ($settings['boleto_fine'] ?? '0')) > 0
                || (float) str_replace(',', '.', (string) ($settings['boleto_interest'] ?? '0')) > 0);
    }

    public static function boleto(float $value, ?int $principalCents = null): void
    {
        self::amount($value);
        if ($value > 0 && ($value < 0.1 || $value > 100)) {
            throw new \InvalidArgumentException('Multa e juros do boleto devem ser zero ou percentuais entre 0,10 e 100.');
        }
        self::belowPrincipal($value, $principalCents);
    }

    /** @param array{type:string,amount:float}|null $rule */
    public static function pix(?array $rule, bool $interest, ?int $principalCents = null): void
    {
        if ($rule === null) {
            return;
        }
        $allowed = $interest ? ['fixed', 'percentage_calendar_days', 'percentage_month_calendar_days'] : ['fixed', 'percentage'];
        if (!in_array($rule['type'], $allowed, true)) {
            throw new \InvalidArgumentException('Selecione uma modalidade explícita de multa ou juros do Pix.');
        }
        self::amount($rule['amount']);
        if ($rule['amount'] <= 0) {
            throw new \InvalidArgumentException('O encargo do Pix deve ser maior que zero. Selecione Não aplicar para desativar.');
        }
        self::belowPrincipal($rule['amount'], $principalCents);
    }

    private static function amount(float $value): void
    {
        if (!is_finite($value) || $value < 0 || abs($value * 100 - round($value * 100)) > 0.00001) {
            throw new \InvalidArgumentException('Informe um encargo não negativo com até duas casas decimais.');
        }
    }

    private static function belowPrincipal(float $value, ?int $principalCents): void
    {
        // The current public API compares the numeric value even for percentages.
        if ($principalCents !== null && (int) round($value * 100) >= $principalCents) {
            throw new \InvalidArgumentException('A API exige que o valor numérico da multa ou dos juros seja menor que o valor da cobrança, inclusive para percentuais.');
        }
    }
}
