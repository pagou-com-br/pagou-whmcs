<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

/**
 * Stable codes for why a charge could not be issued. Provider and exception text is
 * matched to a known code and never stored or shown as received.
 */
final class IssuanceFailure
{
    /** Messages the Pagou charges API answers with, as `{"error": "..."}`. */
    private const PROVIDER = [
        'invalid customer document' => 'invalid_customer_document',
        'invalid customer address' => 'invalid_customer_address',
        'charge min amount not reached' => 'min_amount',
        'charge total must be greater than' => 'min_amount',
        'missing account pix key' => 'missing_pix_key',
        'conta suspensa ou inativa' => 'account_suspended',
        'charge provider rejected the operation' => 'provider_rejected',
        'customer not found' => 'customer_not_found',
        'operation outcome unknown' => 'outcome_unknown',
    ];

    public static function provider(string $text): string
    {
        $normalized = strtolower(trim($text));
        foreach (self::PROVIDER as $message => $code) {
            if ($normalized !== '' && str_contains($normalized, $message)) {
                return $code;
            }
        }

        return 'unknown';
    }

    public static function local(\Throwable $error): string
    {
        $message = $error->getMessage();
        if (str_contains($message, 'CPF ou CNPJ não encontrado')) {
            return 'document_missing';
        }
        if (str_contains($message, 'CPF ou CNPJ inválido')) {
            return 'document_invalid';
        }
        if (str_contains($message, 'address must be in Brazil')) {
            return 'foreign_address';
        }
        if (preg_match('/^Boleto payer ([a-z_]+) is required\.$/', $message, $match) === 1) {
            return 'missing_' . $match[1];
        }
        if (str_contains($message, 'amount of at least BRL 5.00')) {
            return 'min_amount';
        }
        if (str_contains($message, 'due date')) {
            return 'due_date_invalid';
        }
        if (str_contains($message, 'grace period')) {
            return 'grace_period_invalid';
        }

        return 'unknown';
    }

    /** A refusal of the customer's CPF or CNPJ, which the customer can correct on the invoice. */
    public static function documentRefused(string $reason): bool
    {
        return in_array(self::detail($reason), ['document_missing', 'document_invalid', 'invalid_customer_document'], true);
    }

    /** A refusal of the customer data itself: issuing again with the same data is refused too. */
    public static function customerData(string $reason): bool
    {
        $detail = self::detail($reason);

        return self::documentRefused($reason) || str_starts_with($detail, 'missing_')
            || in_array($detail, ['invalid_customer_address', 'customer_not_found', 'foreign_address'], true);
    }

    /** What the customer reads on the invoice when the Pix or the boleto could not be issued. */
    public static function customerMessage(string $reason): string
    {
        $detail = self::detail($reason);
        $charge = str_starts_with($reason, 'pix_') ? 'Pix' : 'boleto';

        return match (true) {
            $detail === 'foreign_address' => 'O boleto exige um endereço no Brasil. Escolha outra forma de pagamento ou atualize o endereço no seu cadastro.',
            self::documentRefused($reason) => 'O ' . $charge . ' precisa de um CPF ou CNPJ válido. Atualize o documento no seu cadastro ou escolha outra forma de pagamento.',
            $detail === 'provider_rejected' => 'O banco não emitiu o boleto. Escolha outra forma de pagamento ou fale com o atendimento.',
            in_array($detail, ['min_amount', 'missing_pix_key', 'account_suspended', 'due_date_invalid', 'grace_period_invalid'], true)
                => 'Não foi possível gerar o ' . $charge . ' agora. Escolha outra forma de pagamento ou fale com o atendimento.',
            $charge === 'Pix' => 'Não foi possível gerar o Pix com os dados do seu cadastro. Confira o CPF ou CNPJ, ou escolha outra forma de pagamento.',
            default => 'Não foi possível gerar o boleto com os dados do seu cadastro. Confira o CPF ou CNPJ e o endereço, ou escolha outra forma de pagamento.',
        };
    }

    private static function detail(string $reason): string
    {
        $parts = explode(':', $reason);

        // A code kept after the retries were exhausted still names its cause last.
        return count($parts) > 1 ? (string) end($parts) : '';
    }
}
