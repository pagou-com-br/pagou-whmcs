<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Presentation;

/**
 * Pure view-model factory for public invoice templates.
 *
 * This layer deliberately accepts only display-safe payment information. It
 * does not perform API calls, generate QR codes or receive card PAN/CVV data.
 */
final class ClientInvoiceView
{
    /** @var array<string, array{label: string, tone: string}> */
    private const STATES = [
        'pending' => ['label' => 'Aguardando pagamento', 'tone' => 'warning'],
        'processing' => ['label' => 'Processando pagamento', 'tone' => 'info'],
        'paid' => ['label' => 'Pagamento confirmado', 'tone' => 'success'],
        'cancelled' => ['label' => 'Cobrança cancelada', 'tone' => 'neutral'],
        'expired' => ['label' => 'Cobrança vencida', 'tone' => 'danger'],
        'failed' => ['label' => 'Não foi possível concluir', 'tone' => 'danger'],
    ];

    /**
     * @param array{state?: string, expiresAt?: string, copyPaste?: string, qrCodeImageUrl?: string, amount?: string, timeline?: list<array{at?: string, label?: string, detail?: string}>} $payment
     * @return array<string, mixed>
     */
    public static function pix(array $payment): array
    {
        return self::payment($payment, 'Pix', [
            'copyPaste' => self::text($payment['copyPaste'] ?? ''),
            'qrCodeImageUrl' => self::qrCodeImageUrl($payment['qrCodeImageUrl'] ?? ''),
        ]);
    }

    /**
     * @param array{state?: string, expiresAt?: string, barcode?: string, digitableLine?: string, pdfUrl?: string, copyPaste?: string, qrCodeImageUrl?: string, amount?: string, timeline?: list<array{at?: string, label?: string, detail?: string}>} $payment
     * @return array<string, mixed>
     */
    public static function boleto(array $payment): array
    {
        return self::payment($payment, 'Boleto', [
            'barcode' => self::text($payment['barcode'] ?? ''),
            'digitableLine' => self::text($payment['digitableLine'] ?? ''),
            'pdfUrl' => self::localUrl($payment['pdfUrl'] ?? ''),
            'copyPaste' => self::text($payment['copyPaste'] ?? ''),
            'qrCodeImageUrl' => self::qrCodeImageUrl($payment['qrCodeImageUrl'] ?? ''),
        ]);
    }

    /**
     * @param array{state?: string, expiresAt?: string, amount?: string, installments?: string, cardBrand?: string, maskedNumber?: string, timeline?: list<array{at?: string, label?: string, detail?: string}>} $payment
     * @return array<string, mixed>
     */
    public static function card(array $payment): array
    {
        return self::payment($payment, 'Cartão de crédito', [
            'installments' => self::text($payment['installments'] ?? ''),
            'cardBrand' => self::text($payment['cardBrand'] ?? ''),
            'maskedNumber' => self::maskedCard($payment['maskedNumber'] ?? ''),
        ]);
    }

    /**
     * @param list<array{name?: string, url?: string, available?: bool}> $artifacts
     * @return list<array{name: string, url: string, available: bool}>
     */
    public static function artifacts(array $artifacts): array
    {
        $result = [];
        foreach ($artifacts as $artifact) {
            $result[] = [
                'name' => self::text($artifact['name'] ?? ''),
                'url' => self::localUrl($artifact['url'] ?? ''),
                'available' => (bool) ($artifact['available'] ?? false),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payment
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private static function payment(array $payment, string $method, array $details): array
    {
        $state = self::state((string) ($payment['state'] ?? 'pending'));

        return array_merge([
            'method' => $method,
            'state' => $state['key'],
            'stateLabel' => $state['label'],
            'stateTone' => $state['tone'],
            'expiresAt' => self::text($payment['expiresAt'] ?? ''),
            'amount' => self::text($payment['amount'] ?? ''),
            'timeline' => self::timeline($payment['timeline'] ?? []),
        ], $details);
    }

    /** @return list<array{at: string, label: string, detail: string}> */
    private static function timeline(mixed $timeline): array
    {
        if (!is_array($timeline)) {
            return [];
        }

        $items = [];
        foreach ($timeline as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $items[] = [
                'at' => self::text($entry['at'] ?? ''),
                'label' => self::text($entry['label'] ?? ''),
                'detail' => self::text($entry['detail'] ?? ''),
            ];
        }

        return $items;
    }

    /** @return array{key: string, label: string, tone: string} */
    private static function state(string $state): array
    {
        $key = strtolower(trim($state));
        $definition = self::STATES[$key] ?? self::STATES['pending'];

        return ['key' => array_key_exists($key, self::STATES) ? $key : 'pending'] + $definition;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? trim((string) $value) : '';
    }

    private static function maskedCard(mixed $value): string
    {
        $value = self::text($value);

        // The view accepts only a conventional masked representation.
        return preg_match('/^(?:[A-Za-z]{2,20}\s+)?[•*xX]{4,16}\s?[•*xX]{0,12}\s?\d{4}$/', $value) === 1 ? $value : '';
    }

    private static function qrCodeImageUrl(mixed $url): string
    {
        $url = self::text($url);

        return preg_match('#^data:image/(?:png|jpeg|webp);base64,[A-Za-z0-9+/=\r\n]+$#', $url) === 1 ? $url : '';
    }

    private static function localUrl(mixed $url): string
    {
        $url = self::text($url);

        return str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : '';
    }
}
