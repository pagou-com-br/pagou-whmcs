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
        $reason = explode(':', (string) ($row['error_message'] ?? ''), 2)[0];
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
            $parts[] = $reasons[$reason] ?? 'Consulte o diagnóstico e o registro administrativo usando o ID da operação';
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
