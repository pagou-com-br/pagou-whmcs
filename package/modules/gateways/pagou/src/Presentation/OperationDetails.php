<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Presentation;

/** Never display provider messages, payloads, tokens or exception text. */
final class OperationDetails
{
    /** @param array<string,mixed> $row */
    public static function describe(array $row): string
    {
        $parts = ['Operação: ' . (string) ($row['id'] ?? '')];
        $error = (string) ($row['error_message'] ?? '');
        $reason = explode(':', $error, 2)[0];
        $explained = self::reason($error);
        $reasons = [
            'boleto_registration_pending' => 'Aguardando registro bancário',
            'boleto_cancellation_pending' => 'Aguardando confirmação do cancelamento',
            'payment_evidence_missing' => 'Aguardando dados assinados do recebimento; confira as notificações',
            'remote_identity_not_yet_recoverable' => 'Vínculo remoto pendente; conferir a operação original',
            'reconciliation_failure' => 'Consulta indisponível; nova tentativa conforme o histórico',
            'boleto_lookup_failed' => 'Consulta de boleto indisponível',
            'late_charges_require_review' => 'Revise os encargos nas configurações',
            'pix_attempt_superseded' => 'Tentativa substituída',
            'boleto_attempt_superseded' => 'Tentativa substituída',
        ];
        if ($reason !== '') {
            $parts[] = $explained ?? $reasons[$reason] ?? 'Consulte o diagnóstico e o registro administrativo usando o ID da operação';
        }
        $created = self::instant($row['created_at'] ?? null);
        $started = self::instant($row['started_at'] ?? null);
        $finished = self::instant($row['finished_at'] ?? null);
        if ($finished === null && ($row['status'] ?? '') === 'succeeded') {
            // Older outbox successes persisted only the final updated_at timestamp.
            $finished = self::instant($row['updated_at'] ?? null);
        }
        if ($created !== null && $started !== null) {
            $parts[] = 'Até a última execução: ' . max(0, $started - $created) . ' s';
        }
        if ($started !== null && $finished !== null) {
            $parts[] = 'Última execução: ' . max(0, $finished - $started) . ' s';
        }
        if (in_array($row['status'] ?? '', ['queued', 'pending', 'retrying'], true) && self::instant($row['available_at'] ?? null) !== null) {
            $date = new \DateTimeImmutable((string) $row['available_at'], new \DateTimeZone('UTC'));
            $parts[] = 'Disponível para consulta: ' . $date->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i:s');
        }
        $telemetry = json_decode((string) ($row['response_json'] ?? '{}'), true);
        $apiTime = is_array($telemetry) ? ($telemetry['api_last_ms'] ?? null) : null;
        if ((is_int($apiTime) || is_float($apiTime)) && is_finite((float) $apiTime) && $apiTime >= 0) {
            $parts[] = 'Última chamada HTTP à API (rede incluída): ' . number_format($apiTime, 1, ',', '.') . ' ms';
        }
        return implode(' · ', $parts);
    }

    /**
     * Why an operation stopped, in plain Portuguese, from the module's own stable codes.
     * Returns null for a code without a known explanation.
     */
    public static function reason(string $error): ?string
    {
        [$head, $detail] = array_pad(explode(':', $error, 2), 2, '');
        if ($head === 'retry_exhausted') {
            return self::reason($detail) ?? 'Sem sucesso após várias tentativas';
        }
        if (in_array($head, ['boleto_request_rejected', 'pix_request_rejected'], true)) {
            return match ($detail) {
                'invalid_customer_document' => 'A Pagou recusou o CPF ou CNPJ do cliente. Corrija o cadastro e gere a cobrança novamente',
                'invalid_customer_address' => 'A Pagou recusou o endereço do cliente. Confira CEP, rua, cidade e estado no cadastro',
                'min_amount' => 'Valor abaixo do mínimo aceito pela Pagou',
                'missing_pix_key' => 'A conta Pagou não tem chave Pix cadastrada',
                'account_suspended' => 'A conta Pagou está suspensa ou inativa',
                'provider_rejected' => 'O banco emissor recusou a cobrança. Gere novamente mais tarde',
                'customer_not_found' => 'Cliente não encontrado na Pagou',
                default => 'A Pagou recusou a cobrança. Confira o CPF ou CNPJ e o endereço do cliente',
            };
        }
        if (in_array($head, ['boleto_local_validation', 'pix_local_validation'], true)) {
            $fields = ['name' => 'o nome', 'document' => 'o CPF ou CNPJ', 'email' => 'o e-mail', 'phone' => 'o telefone',
                'zip' => 'o CEP', 'street' => 'o endereço', 'number' => 'o número', 'neighborhood' => 'o bairro',
                'city' => 'a cidade', 'state' => 'o estado'];
            if (str_starts_with($detail, 'missing_') && isset($fields[substr($detail, 8)])) {
                return 'Falta ' . $fields[substr($detail, 8)] . ' no cadastro do cliente';
            }
            return match ($detail) {
                'document_missing' => 'CPF ou CNPJ não encontrado no cadastro do cliente, nos campos configurados',
                'document_invalid' => 'CPF ou CNPJ inválido no cadastro do cliente, conferido pelos dígitos verificadores',
                'foreign_address' => 'O boleto exige endereço no Brasil, e o cadastro do cliente está em outro país',
                'min_amount' => 'Valor abaixo do mínimo de R$ 5,00 para boleto',
                'due_date_invalid' => 'Data de vencimento inválida',
                'grace_period_invalid' => 'Prazo de pagamento após o vencimento inválido nas configurações',
                default => 'Dados do cliente incompletos para emitir a cobrança. Confira o CPF ou CNPJ e o endereço no cadastro',
            };
        }

        return match ($head) {
            'due_date_requires_review' => 'Revise o vencimento da fatura antes de emitir a cobrança',
            'boleto_remote_outcome_unknown' => 'A Pagou não confirmou a emissão. O módulo confere na Pagou se o boleto foi criado antes de emitir de novo, sem gerar boleto em dobro',
            'pix_remote_outcome_unknown', 'pix_remote_failure' => 'A Pagou não confirmou a emissão do Pix. Para não gerar cobrança em dobro, a emissão não é repetida automaticamente',
            'boleto_pdf_unavailable' => 'PDF do boleto indisponível; a fatura é enviada sem o anexo',
            'boleto_paid_during_cancellation' => 'O boleto foi pago durante o cancelamento; o pagamento é registrado normalmente',
            'boleto_cancel_confirmation_invalid' => 'Confirmação sem a fatura de referência; o módulo refaz a conferência',
            'boleto_cancel_unknown', 'pix_cancel_unknown' => 'A Pagou não confirmou o cancelamento; a cobrança é conferida novamente',
            'settled_by_charge_state' => 'Encerrada: a cobrança já foi resolvida',
            'boleto_issue_recovery_window_open' => 'Conferindo na Pagou se o boleto foi criado; a emissão só é repetida se ele não existir',
            'boleto_issue_recovery_lookup_failed' => 'Consulta à Pagou indisponível; nova conferência em seguida',
            'boleto_issue_recovery_ambiguous' => 'A Pagou lista mais de um boleto para esta emissão; confira na Pagou antes de gerar outro',
            'boleto_issue_recovery_exhausted' => 'A Pagou não confirmou a emissão após várias tentativas; gere um novo boleto pelo card da fatura ou confira com a Pagou',
            default => null,
        };
    }

    private static function instant(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }
}
