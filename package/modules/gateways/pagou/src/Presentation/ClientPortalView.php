<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Presentation;

/** Builds the limited, customer-owned payment list used by the client area. */
final class ClientPortalView
{
    /**
     * @param list<array{invoiceNumber?: string, method?: string, status?: string, amount?: string, dueDate?: string, actionUrl?: string}> $payments
     * @return list<array{invoiceNumber: string, method: string, status: string, amount: string, dueDate: string, actionUrl: string}>
     */
    public static function payments(array $payments): array
    {
        $result = [];
        foreach ($payments as $payment) {
            $result[] = [
                'invoiceNumber' => self::text($payment['invoiceNumber'] ?? ''),
                'method' => self::method($payment['method'] ?? ''),
                'status' => self::status($payment['status'] ?? ''),
                'amount' => self::text($payment['amount'] ?? ''),
                'dueDate' => self::date($payment['dueDate'] ?? ''),
                'actionUrl' => self::localUrl($payment['actionUrl'] ?? ''),
            ];
        }

        return $result;
    }

    private static function method(mixed $method): string
    {
        return match (strtolower(self::text($method))) {
            'pix' => 'Pix',
            'boleto' => 'Boleto',
            'card', 'cartao', 'cartão' => 'Cartão de crédito',
            default => 'Pagamento',
        };
    }

    private static function status(mixed $status): string
    {
        return match (strtolower(self::text($status))) {
            'paid', 'completed' => 'Pago',
            'settled' => 'Liquidado',
            'authorized' => 'Autorizado',
            'charged_back' => 'Contestado',
            'processing' => 'Processando',
            'cancelled', 'canceled' => 'Cancelado',
            'refunded', 'reversed' => 'Estornado',
            'superseded' => 'Substituída',
            'action_required' => 'Autenticação necessária',
            'cancel_requested' => 'Cancelamento em andamento',
            'uncertain', 'unknown' => 'Em conferência',
            'queued', 'awaiting_registration', 'started' => 'Em preparação',
            'pending', 'ready' => 'Aguardando pagamento',
            'expired' => 'Vencido',
            'failed' => 'Falhou',
            'rejected' => 'Recusado',
            default => 'Em atualização',
        };
    }

    private static function date(mixed $value): string
    {
        $value = self::text($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $date->format('d/m/Y') : '';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? trim((string) $value) : '';
    }

    private static function localUrl(mixed $url): string
    {
        $url = self::text($url);

        return preg_match('/^viewinvoice\.php\?id=[1-9]\d*$/', $url) === 1 ? $url : '';
    }
}
