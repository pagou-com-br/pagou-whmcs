<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\Support;

final class ReportFormat
{
    public static function money(?int $cents): string
    {
        return $cents === null ? 'Não identificado' : 'R$ ' . self::decimal($cents, true);
    }

    /** Axis and tick values: whole reais without cents, cents only when present. */
    public static function compactMoney(int $cents): string
    {
        return $cents % 100 === 0
            ? 'R$ ' . number_format(intdiv($cents, 100), 0, ',', '.')
            : self::money($cents);
    }

    public static function decimal(int $cents, bool $group = false): string
    {
        $whole = (string) intdiv(abs($cents), 100);
        if ($group) {
            $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole) ?? $whole;
        }
        return ($cents < 0 ? '-' : '') . $whole . ',' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function date(?string $date): string
    {
        if ($date === null || $date === '') {
            return 'Não informado';
        }
        try {
            $instant = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
            if (strlen($date) === 10) {
                return $instant->format('d/m/Y');
            }
            return $instant->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i');
        } catch (\Exception) {
            return 'Data indisponível';
        }
    }

    public static function label(string $value): string
    {
        return match ($value) {
            'pix' => 'Pix', 'boleto' => 'Boleto', 'card' => 'Cartão', 'unknown' => 'Não identificado',
            'applied' => 'Conciliado no WHMCS', 'applying' => 'Baixa em processamento', 'received' => 'Recebimento registrado',
            'quarantined' => 'Requer conferência', 'overdue' => 'Vencida', 'open' => 'Em aberto',
            'queued' => 'Na fila', 'pending' => 'Pendente', 'ready' => 'Pronta', 'active' => 'Ativa',
            'paid', 'Paid' => 'Paga', 'Unpaid' => 'Não paga', 'failed' => 'Falha', 'succeeded' => 'Concluída',
            'cancelled', 'canceled', 'Cancelled' => 'Cancelada', 'superseded' => 'Substituída',
            'refunded', 'Refunded' => 'Reembolsada', 'partially_refunded' => 'Reembolso parcial',
            'cancel_requested' => 'Cancelamento solicitado', 'awaiting_registration' => 'Aguardando registro bancário',
            'authorized' => 'Autorizada', 'action_required' => 'Autenticação pendente', 'reversed' => 'Autorização revertida', 'charged_back' => 'Contestada',
            'uncertain' => 'Resultado incerto', 'unavailable' => 'Indisponível', 'dispatching' => 'Solicitação em envio',
            'retrying' => 'Nova tentativa', 'leased' => 'Em execução', 'high' => 'Alta', 'medium' => 'Média', 'low' => 'Baixa',
            'critical' => 'Crítica', 'history_unavailable' => 'Histórico indisponível',
            'refund_done' => 'Devolvida', 'refund_confirmed' => 'Confirmada, registro pendente no WHMCS', 'refund_progress' => 'Em andamento',
            'refund_review' => 'Em conferência', 'refund_rejected' => 'Não aceita',
            'payment_application_failed' => 'Baixa requer conferência', 'amount_mismatch', 'payment_amount_mismatch', 'card_amount_mismatch' => 'Valor divergente',
            'invoice_not_found', 'missing_invoice' => 'Fatura não localizada', 'currency_mismatch' => 'Moeda divergente',
            'payment_evidence_missing' => 'Dados do recebimento pendentes', 'payment_during_cancellation' => 'Pago durante cancelamento',
            'late_charges_require_review' => 'Encargos requerem revisão',
            'due_date_requires_review' => 'Vencimento requer revisão', 'card_remote_identity_unavailable', 'remote_identity_unavailable' => 'Vínculo remoto pendente',
            'card_chargeback_requires_review' => 'Contestação requer conferência', 'card_unknown_provider_status' => 'Estado do cartão desconhecido',
            'card_deletion_requires_review' => 'Remoção do cartão requer revisão',
            'pix_refund_requires_review' => 'Reembolso Pix requer conferência',
            'pix_refund_external_requires_review' => 'Devolução Pix sem solicitação no módulo',
            'card_recovery_response_mismatch' => 'Resposta do cartão divergente',
            'issue_pix' => 'Emitir Pix', 'issue_boleto' => 'Emitir boleto', 'fetch_boleto_pdf' => 'Preparar PDF',
            'invoice_email_delivery_uncertain' => 'Envio de e-mail requer conferência',
            'invoice_email_template_invalid' => 'Template de e-mail inválido',
            'deliver_invoice_email' => 'Enviar instruções por e-mail', 'reconcile_payment' => 'Consultar pagamento',
            'reconcile_uncertain_operation' => 'Consultar resultado incerto', 'replace_boleto' => 'Confirmar cancelamento do boleto',
            'card.capture' => 'Capturar cartão', 'card.refund' => 'Reembolsar cartão', 'card.cancel' => 'Cancelar cartão',
            'card.reverse' => 'Reverter autorização', 'card.retry' => 'Repetir cartão',
            default => \Pagou\Whmcs\Presentation\PaymentLabels::label($value),
        };
    }
}
