<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Presentation;

/** Shared operator vocabulary for operations, events and payment states. */
final class PaymentLabels
{
    public static function label(string $value): string
    {
        $labels = [
            'late_charges_require_review' => 'Encargos requerem revisão',
            'qrcode.completed' => 'Pix recebido',
            'qrcode.refunded' => 'Pix devolvido',
            'charge.created' => 'Boleto registrado',
            'charge.rejected' => 'Boleto rejeitado',
            'charge.canceled' => 'Boleto cancelado',
            'charge.paid' => 'Boleto pago',
            'charge.processed' => 'Boleto processado',
            'creditcard.transaction.updated' => 'Transação de cartão atualizada',
            'creditcard.chargeback.updated' => 'Contestação de cartão atualizada',
            'pix' => 'Pix',
            'boleto' => 'Boleto',
            'card' => 'Cartão',
            'paid' => 'Pago',
            'settled' => 'Liquidado',
            'authorized' => 'Autorizado',
            'action_required' => 'Requer autenticação',
            'reversed' => 'Revertido',
            'charged_back' => 'Contestado',
            'unknown' => 'Estado desconhecido',
            'ready' => 'Aguardando pagamento',
            'awaiting_registration' => 'Aguardando registro',
            'reserved' => 'Recebida',
            'rejected' => 'Rejeitada',
            'critical' => 'Crítica',
            'pending' => 'Pendente',
            'leased' => 'Em processamento',
            'uncertain' => 'Aguardando conciliação',
            'queued' => 'Na fila',
            'retryable_failure' => 'Aguardando nova consulta',
            'permanent_failure' => 'Revisão necessária',
            'started' => 'Em andamento',
            'retrying' => 'Nova tentativa',
            'succeeded' => 'Concluída',
            'completed' => 'Concluído',
            'processed' => 'Processado',
            'failed' => 'Falhou',
            'superseded' => 'Substituída',
            'ignored' => 'Ignorada',
            'cancelled' => 'Cancelado',
            'canceled' => 'Cancelado',
            'refunded' => 'Reembolsado',
            'partially_refunded' => 'Reembolso parcial',
            'cancel_requested' => 'Cancelamento solicitado',
            'dispatching' => 'Solicitação em envio',
            'captured' => 'Capturado',
            'active' => 'Ativa',
            'unavailable' => 'Indisponível',
            'open' => 'Aberta',
            'high' => 'Alta',
            'medium' => 'Média',
            'low' => 'Baixa',
            'issue_pix' => 'Gerar Pix',
            'pix.create' => 'Gerar Pix',
            'pix.create_due' => 'Gerar Pix com vencimento',
            'pix.refund' => 'Estornar Pix',
            'pix.cancel' => 'Cancelar Pix',
            'cancel_pix' => 'Cancelar Pix',
            'issue_boleto' => 'Gerar boleto',
            'fetch_boleto_pdf' => 'Obter PDF do boleto',
            'deliver_invoice_email' => 'Enviar fatura por e-mail',
            'cancel_boleto' => 'Cancelar boleto',
            'replace_boleto' => 'Confirmar cancelamento do boleto',
            'reconcile_payment' => 'Conciliar pagamento',
            'reconcile_uncertain_operation' => 'Conciliar operação incerta',
            'card.customer.create' => 'Cadastrar cliente do cartão',
            'card.card.create' => 'Tokenizar cartão',
            'card.charge.create' => 'Cobrar cartão',
            'card.charge.capture' => 'Capturar cartão',
            'card.charge.reverse' => 'Estornar cartão',
            'card.charge.cancel' => 'Cancelar cartão',
            'card.charge.retry' => 'Tentar cartão novamente',
            'card.charge.refund' => 'Reembolsar cartão',
            'card_refund_confirmed_requires_review' => 'Estorno confirmado na Pagou: confira o registro financeiro no WHMCS',
            'card_remote_identity_unavailable' => 'Cobrança sem identificação remota: confira na Pagou antes de tentar novamente',
            'card_chargeback_requires_review' => 'Contestação de cartão: revisão financeira necessária',
            'card.charge.recurring' => 'Renovação automática no cartão',
            // Finding types, as the reports name them, for the findings history.
            'payment_application_failed' => 'Baixa requer conferência',
            'amount_mismatch' => 'Valor divergente', 'payment_amount_mismatch' => 'Valor divergente', 'card_amount_mismatch' => 'Valor divergente',
            'invoice_not_found' => 'Fatura não localizada', 'missing_invoice' => 'Fatura não localizada', 'currency_mismatch' => 'Moeda divergente',
            'payment_evidence_missing' => 'Dados do recebimento pendentes', 'payment_during_cancellation' => 'Pago durante cancelamento',
            'due_date_requires_review' => 'Vencimento requer revisão',
            'remote_identity_unavailable' => 'Vínculo remoto pendente', 'card_unknown_provider_status' => 'Estado do cartão desconhecido',
            'card_deletion_requires_review' => 'Remoção do cartão requer revisão', 'pix_refund_requires_review' => 'Reembolso Pix requer conferência',
            'pix_refund_external_requires_review' => 'Devolução Pix sem solicitação no módulo', 'card_recovery_response_mismatch' => 'Resposta do cartão divergente',
            'invoice_email_delivery_uncertain' => 'Envio de e-mail requer conferência',
        ];
        if (isset($labels[$value])) {
            return $labels[$value];
        }

        return ucfirst(str_replace(['_', '.'], ' ', trim($value)));
    }
}
