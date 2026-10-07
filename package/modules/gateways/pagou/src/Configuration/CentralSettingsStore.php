<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Configuration;

use PDO;
use PDOException;
use Pagou\Whmcs\Payment\LateChargeRules;

/** Persists the single source of truth for the addon and all Pagou gateways. */
final class CentralSettingsStore
{
    /** @var array<string, string> */
    private const DEFAULTS = [
        'cpf_field_id' => '',
        'cnpj_field_id' => '',
        'prefer_cnpj' => '0',
        'worker_max_jobs' => '25',
        'worker_max_seconds' => '20',
        'retention_operational_days' => '90',
        'admin_alerts_enabled' => '1',
        'pix_late_charges_reviewed' => '0',
        'boleto_late_charges_reviewed' => '0',
        'pix_due_enabled' => '0',
        'pix_expiration_seconds' => '2592000',
        'pix_due_expiration_days' => '30',
        'pix_due_fine_type' => 'none',
        'pix_due_fine_amount' => '0.00',
        'pix_due_interest_type' => 'none',
        'pix_due_interest_amount' => '0.00',
        'pix_due_respect_late_fees' => '0',
        'pix_min_amount' => '',
        'pix_max_amount' => '',
        'pix_fee_percent' => '0.00',
        'pix_fee_fixed' => '0.00',
        'pix_fee_exempt_at' => '',
        'pix_fee_respect_late_fees' => '0',
        'pix_email_details' => '1',
        'pix_hide_on_error' => '1',
        'pix_show_qr' => '1',
        'pix_show_copy_paste' => '1',
        'pix_show_notes' => '0',
        'pix_notes' => '',
        'boleto_grace_period' => '30',
        'boleto_fine' => '0.00',
        'boleto_interest' => '0.00',
        'boleto_min_amount' => '',
        'boleto_max_amount' => '',
        'boleto_fee_percent' => '0.00',
        'boleto_fee_fixed' => '0.00',
        'boleto_fee_exempt_at' => '',
        'boleto_fee_respect_late_fees' => '0',
        'boleto_respect_late_fees' => '0',
        'boleto_email_details' => '1',
        'boleto_hide_on_error' => '1',
        'boleto_email_pdf_mode' => 'attach',
        'boleto_email_template' => 'Invoice Created',
        'boleto_show_line' => '1',
        'boleto_show_pdf' => '1',
        'boleto_show_qr' => '0',
        'boleto_show_notes' => '0',
        'boleto_notes' => '',
        'card_max_installments' => '1',
        'card_auto_capture' => '1',
        'card_soft_descriptor' => 'PAGOU',
        'card_min_amount' => '',
        'card_max_amount' => '',
        'card_fee_percent' => '0.00',
        'card_fee_fixed' => '0.00',
        'card_fee_exempt_at' => '',
        'card_fee_respect_late_fees' => '0',
    ];

    /** @var array<string, list<string>> */
    private const SECTIONS = [
        'general' => [
            'cpf_field_id', 'cnpj_field_id', 'prefer_cnpj', 'worker_max_jobs', 'worker_max_seconds',
            'retention_operational_days', 'admin_alerts_enabled',
        ],
        'pix' => [
            'pix_late_charges_reviewed', 'pix_due_enabled', 'pix_expiration_seconds', 'pix_due_expiration_days',
            'pix_due_fine_type', 'pix_due_fine_amount', 'pix_due_interest_type', 'pix_due_interest_amount',
            'pix_due_respect_late_fees',
            'pix_min_amount', 'pix_max_amount', 'pix_fee_percent', 'pix_fee_fixed', 'pix_fee_exempt_at',
            'pix_fee_respect_late_fees', 'pix_email_details', 'pix_hide_on_error', 'pix_show_qr',
            'pix_show_copy_paste', 'pix_show_notes', 'pix_notes',
        ],
        'boleto' => [
            'boleto_late_charges_reviewed', 'boleto_grace_period', 'boleto_fine', 'boleto_interest', 'boleto_min_amount', 'boleto_max_amount',
            'boleto_fee_percent', 'boleto_fee_fixed', 'boleto_fee_exempt_at', 'boleto_fee_respect_late_fees',
            'boleto_respect_late_fees',
            'boleto_email_details', 'boleto_hide_on_error', 'boleto_email_pdf_mode', 'boleto_email_template',
            'boleto_show_line', 'boleto_show_pdf', 'boleto_show_qr',
            'boleto_show_notes', 'boleto_notes',
        ],
        'card' => [
            'card_max_installments', 'card_auto_capture', 'card_soft_descriptor', 'card_min_amount',
            'card_max_amount', 'card_fee_percent', 'card_fee_fixed', 'card_fee_exempt_at',
            'card_fee_respect_late_fees',
        ],
    ];

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /** @return list<string> */
    public static function keysForSection(string $section): array
    {
        return self::SECTIONS[$section] ?? self::SECTIONS['general'];
    }

    /** @return list<string> */
    public static function sections(): array
    {
        return array_keys(self::SECTIONS);
    }

    /** @return array<string, string> */
    public static function defaultsForSection(string $section): array
    {
        if (!array_key_exists($section, self::SECTIONS)) {
            throw new \InvalidArgumentException('A seção de configurações informada é inválida.');
        }

        return array_intersect_key(self::DEFAULTS, array_flip(self::SECTIONS[$section]));
    }

    /** @return array<string, string> */
    public function values(): array
    {
        $values = self::DEFAULTS;
        $statement = $this->pdo->query(
            'SELECT setting_key, setting_value FROM pagou_settings WHERE is_secret = 0'
        );
        if ($statement === false) {
            return $values;
        }
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $key = (string) ($row['setting_key'] ?? '');
            if (array_key_exists($key, $values)) {
                $values[$key] = (string) ($row['setting_value'] ?? '');
            }
        }

        return $values;
    }

    /**
     * @param array<string, string> $input
     * @return array<string, string>
     */
    public function save(array $input): array
    {
        $confirmation = $input;
        $current = $this->values();
        $values = $this->normalize(array_replace($current, $input));
        if (array_intersect(array_keys($input), ['card_max_installments', 'card_auto_capture']) !== []) {
            \Pagou\Whmcs\Payment\Card\CardCheckoutPolicy::assertSupported(
                (int) $values['card_max_installments'],
                $values['card_auto_capture'] === '1',
            );
        }
        foreach (['pix', 'boleto'] as $method) {
            $keys = $method === 'pix'
                ? ['pix_due_fine_type', 'pix_due_fine_amount', 'pix_due_interest_type', 'pix_due_interest_amount']
                : ['boleto_fine', 'boleto_interest'];
            $reviewKey = $method . '_late_charges_reviewed';
            // A hidden/missing checkbox or a save in another section must not acknowledge old values.
            $values[$reviewKey] = $current[$reviewKey];
            unset($input[$reviewKey]);
            if (array_intersect(array_keys($input), $keys) === []) {
                continue;
            }
            if ($method === 'pix') {
                if ($values['pix_due_interest_type'] === 'percentage') {
                    throw new \InvalidArgumentException('Selecione se os juros do Pix são ao dia ou ao mês. A opção percentual antiga não será convertida automaticamente.');
                }
                foreach (['fine', 'interest'] as $kind) {
                    $amount = (float) $values['pix_due_' . $kind . '_amount'];
                    $type = $values['pix_due_' . $kind . '_type'];
                    if ($type !== 'none' && $amount > 0) {
                        LateChargeRules::pix(['type' => $type, 'amount' => $amount], $kind === 'interest');
                    }
                }
            } else {
                LateChargeRules::boleto((float) $values['boleto_fine']);
                LateChargeRules::boleto((float) $values['boleto_interest']);
            }
            if (
                LateChargeRules::needsReview($current, $method)
                && LateChargeRules::needsReview($values, $method)
                && ($confirmation[$reviewKey] ?? '0') !== '1'
            ) {
                throw new \InvalidArgumentException('Revise os encargos antigos e confirme as unidades e a periodicidade antes de salvar.');
            }
            $input[$reviewKey] = $values[$reviewKey] = '1';
        }
        $ownsTransaction = !$this->pdo->inTransaction();

        try {
            if ($ownsTransaction) {
                $this->pdo->beginTransaction();
            }
            foreach (array_keys($input) as $key) {
                if (array_key_exists($key, $values)) {
                    $this->upsert($key, $values[$key]);
                }
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $values;
    }

    /** @return array<string, string> */
    public function resetSection(string $section): array
    {
        return $this->save(self::defaultsForSection($section));
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function normalize(array $values): array
    {
        $normalized = [
            'cpf_field_id' => $this->optionalId($values['cpf_field_id'] ?? '', 'CPF/CNPJ'),
            'cnpj_field_id' => $this->optionalId($values['cnpj_field_id'] ?? '', 'CNPJ alternativo'),
            'prefer_cnpj' => $this->boolean($values['prefer_cnpj'] ?? ''),
            'worker_max_jobs' => $this->integer($values, 'worker_max_jobs', 1, 200, 'Operações por execução'),
            'worker_max_seconds' => $this->integer($values, 'worker_max_seconds', 10, 300, 'Orçamento do worker'),
            'retention_operational_days' => $this->integer($values, 'retention_operational_days', 30, 730, 'Retenção operacional'),
            'admin_alerts_enabled' => $this->boolean($values['admin_alerts_enabled'] ?? ''),
            'pix_late_charges_reviewed' => $this->boolean($values['pix_late_charges_reviewed'] ?? ''),
            'boleto_late_charges_reviewed' => $this->boolean($values['boleto_late_charges_reviewed'] ?? ''),
            'pix_due_enabled' => $this->boolean($values['pix_due_enabled'] ?? ''),
            'pix_expiration_seconds' => $this->integer($values, 'pix_expiration_seconds', 60, 2592000, 'Expiração do Pix imediato'),
            'pix_due_expiration_days' => $this->integer($values, 'pix_due_expiration_days', 1, 365, 'Validade do Pix com vencimento'),
            'pix_due_fine_type' => $this->enum($values['pix_due_fine_type'] ?? '', ['none', 'fixed', 'percentage'], 'Tipo de multa do Pix'),
            'pix_due_fine_amount' => $this->decimal($values['pix_due_fine_amount'] ?? '', false, null, 'Multa do Pix'),
            'pix_due_interest_type' => $this->enum($values['pix_due_interest_type'] ?? '', array_merge(array_keys(LateChargeRules::interestOptions()), ['percentage']), 'Tipo de juros do Pix'),
            'pix_due_interest_amount' => $this->decimal($values['pix_due_interest_amount'] ?? '', false, null, 'Juros do Pix'),
            'pix_due_respect_late_fees' => $this->boolean($values['pix_due_respect_late_fees'] ?? ''),
            'pix_min_amount' => $this->decimal($values['pix_min_amount'] ?? '', true, null, 'Limite mínimo do Pix'),
            'pix_max_amount' => $this->decimal($values['pix_max_amount'] ?? '', true, null, 'Limite máximo do Pix'),
            'pix_fee_percent' => $this->decimal($values['pix_fee_percent'] ?? '', false, 100, 'Taxa percentual do Pix'),
            'pix_fee_fixed' => $this->decimal($values['pix_fee_fixed'] ?? '', false, null, 'Taxa fixa do Pix'),
            'pix_fee_exempt_at' => $this->decimal($values['pix_fee_exempt_at'] ?? '', true, null, 'Isenção de taxa do Pix'),
            'pix_fee_respect_late_fees' => $this->boolean($values['pix_fee_respect_late_fees'] ?? ''),
            'pix_email_details' => $this->boolean($values['pix_email_details'] ?? ''),
            'pix_hide_on_error' => $this->boolean($values['pix_hide_on_error'] ?? ''),
            'pix_show_qr' => $this->boolean($values['pix_show_qr'] ?? ''),
            'pix_show_copy_paste' => $this->boolean($values['pix_show_copy_paste'] ?? ''),
            'pix_show_notes' => $this->boolean($values['pix_show_notes'] ?? ''),
            'pix_notes' => $this->plainText($values['pix_notes'] ?? '', 2000, 'Observações do Pix'),
            'boleto_grace_period' => $this->integer($values, 'boleto_grace_period', 1, 30, 'Prazo de pagamento do boleto'),
            'boleto_fine' => $this->decimal($values['boleto_fine'] ?? '', false, 100, 'Multa do boleto'),
            'boleto_interest' => $this->decimal($values['boleto_interest'] ?? '', false, 100, 'Juros do boleto'),
            'boleto_min_amount' => $this->decimal($values['boleto_min_amount'] ?? '', true, null, 'Limite mínimo do boleto'),
            'boleto_max_amount' => $this->decimal($values['boleto_max_amount'] ?? '', true, null, 'Limite máximo do boleto'),
            'boleto_fee_percent' => $this->decimal($values['boleto_fee_percent'] ?? '', false, 100, 'Taxa percentual do boleto'),
            'boleto_fee_fixed' => $this->decimal($values['boleto_fee_fixed'] ?? '', false, null, 'Taxa fixa do boleto'),
            'boleto_fee_exempt_at' => $this->decimal($values['boleto_fee_exempt_at'] ?? '', true, null, 'Isenção de taxa do boleto'),
            'boleto_fee_respect_late_fees' => $this->boolean($values['boleto_fee_respect_late_fees'] ?? ''),
            'boleto_respect_late_fees' => $this->boolean($values['boleto_respect_late_fees'] ?? ''),
            'boleto_email_details' => $this->boolean($values['boleto_email_details'] ?? ''),
            'boleto_hide_on_error' => $this->boolean($values['boleto_hide_on_error'] ?? ''),
            'boleto_email_pdf_mode' => $this->enum($values['boleto_email_pdf_mode'] ?? '', ['attach', 'link', 'none'], 'Envio do PDF do boleto'),
            'boleto_email_template' => $this->plainText($values['boleto_email_template'] ?? '', 100, 'Template de e-mail do boleto'),
            'boleto_show_line' => $this->boolean($values['boleto_show_line'] ?? ''),
            'boleto_show_pdf' => $this->boolean($values['boleto_show_pdf'] ?? ''),
            'boleto_show_qr' => $this->boolean($values['boleto_show_qr'] ?? ''),
            'boleto_show_notes' => $this->boolean($values['boleto_show_notes'] ?? ''),
            'boleto_notes' => $this->plainText($values['boleto_notes'] ?? '', 2000, 'Observações do boleto'),
            'card_max_installments' => $this->integer($values, 'card_max_installments', 1, 12, 'Máximo de parcelas do cartão'),
            'card_auto_capture' => $this->boolean($values['card_auto_capture'] ?? ''),
            'card_soft_descriptor' => $this->descriptor($values['card_soft_descriptor'] ?? ''),
            'card_min_amount' => $this->decimal($values['card_min_amount'] ?? '', true, null, 'Limite mínimo do cartão'),
            'card_max_amount' => $this->decimal($values['card_max_amount'] ?? '', true, null, 'Limite máximo do cartão'),
            'card_fee_percent' => $this->decimal($values['card_fee_percent'] ?? '', false, 100, 'Taxa percentual do cartão'),
            'card_fee_fixed' => $this->decimal($values['card_fee_fixed'] ?? '', false, null, 'Taxa fixa do cartão'),
            'card_fee_exempt_at' => $this->decimal($values['card_fee_exempt_at'] ?? '', true, null, 'Isenção de taxa do cartão'),
            'card_fee_respect_late_fees' => $this->boolean($values['card_fee_respect_late_fees'] ?? ''),
        ];

        foreach (['pix', 'boleto', 'card'] as $method) {
            $minimum = $normalized[$method . '_min_amount'];
            $maximum = $normalized[$method . '_max_amount'];
            if ($minimum !== '' && $maximum !== '' && (float) $minimum >= (float) $maximum) {
                throw new \InvalidArgumentException('O limite máximo deve ser maior que o limite mínimo.');
            }
        }

        return $normalized;
    }

    private function boolean(string $value): string
    {
        return in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true', 'enabled'], true) ? '1' : '0';
    }

    /** @param list<string> $allowed */
    private function enum(string $value, array $allowed, string $label): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException($label . ' possui uma opção inválida.');
        }

        return $value;
    }

    private function decimal(string $value, bool $optional, ?float $maximum, string $label): string
    {
        $value = str_replace(',', '.', trim($value));
        if ($value === '' && $optional) {
            return '';
        }
        if (preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' deve ser um valor positivo com até duas casas decimais.');
        }
        $number = (float) $value;
        if ($maximum !== null && $number > $maximum) {
            throw new \InvalidArgumentException(sprintf('%s não pode ser maior que %.2f.', $label, $maximum));
        }

        return number_format($number, 2, '.', '');
    }

    private function plainText(string $value, int $maximum, string $label): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (mb_strlen($value) > $maximum) {
            throw new \InvalidArgumentException(sprintf('%s aceita no máximo %d caracteres.', $label, $maximum));
        }

        return strip_tags($value);
    }

    private function descriptor(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '' || mb_strlen($value) > 22 || preg_match('/^[A-Z0-9 .-]+$/', $value) !== 1) {
            throw new \InvalidArgumentException('A identificação na fatura do cartão deve ter de 1 a 22 letras, números, espaços, pontos ou hífens.');
        }

        return $value;
    }

    private function optionalId(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[1-9]\d{0,8}$/', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' deve ser o ID numérico positivo de um campo personalizado.');
        }

        return (string) (int) $value;
    }

    /** @param array<string, string> $values */
    private function integer(array $values, string $key, int $minimum, int $maximum, string $label): string
    {
        $value = trim($values[$key] ?? '');
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' deve ser um número inteiro.');
        }
        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) {
            throw new \InvalidArgumentException(sprintf(
                '%s deve ficar entre %d e %d.',
                $label,
                $minimum,
                $maximum,
            ));
        }

        return (string) $integer;
    }

    private function upsert(string $key, string $value): void
    {
        $parameters = [
            'key' => $key,
            'value' => $value,
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ];
        $update = $this->pdo->prepare(
            'UPDATE pagou_settings SET setting_value = :value, is_secret = 0, updated_at = :updated_at '
            . 'WHERE setting_key = :key AND is_secret = 0'
        );
        $update->execute($parameters);
        if ($update->rowCount() > 0) {
            return;
        }

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO pagou_settings (setting_key, setting_value, is_secret, updated_at) '
                . 'VALUES (:key, :value, 0, :updated_at)'
            );
            $insert->execute($parameters);
        } catch (PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['19', '23000', '23505'], true)) {
                throw $exception;
            }
            $update->execute($parameters);
        }
    }
}
