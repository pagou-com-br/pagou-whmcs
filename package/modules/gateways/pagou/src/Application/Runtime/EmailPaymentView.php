<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

/**
 * Email-safe markup. No browser assets, form controls, data URLs or live interactions;
 * the only image is the QR Code served from a signed HTTPS address.
 */
final class EmailPaymentView
{
    /** @param array<string, mixed> $display */
    public static function render(string $method, array $display, string $invoiceUrl, string $pdfUrl, string $qrUrl = '', string $boletoUrl = '', string $boletoIconUrl = ''): string
    {
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #dbe3ef;border-radius:8px;margin:20px 0;background:#ffffff;"><tr><td style="padding:20px;font-family:Arial,sans-serif;color:#25324a;font-size:14px;line-height:1.6;">'
            . '<strong style="font-size:16px;display:block;margin-bottom:12px;">' . ($method === 'boleto' ? 'Pagamento por boleto' : 'Pagamento por Pix') . '</strong>';
        if (!empty($display['amount'])) {
            $html .= '<p style="margin:0 0 8px;">Valor da cobrança: <strong>R$ ' . $escape((string) $display['amount']) . '</strong></p>';
        }
        $due = (string) ($display['dueDate'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) === 1) {
            $html .= '<p style="margin:0 0 16px;">Vencimento: <strong>' . substr($due, 8, 2) . '/' . substr($due, 5, 2) . '/' . substr($due, 0, 4) . '</strong></p>';
        }
        if ($method === 'pix' && preg_match('#^https://#i', $qrUrl) === 1) {
            $html .= '<p style="margin:0 0 8px;">Abra o app do seu banco e escaneie o QR Code:</p>'
                . '<p style="margin:0 0 16px;text-align:center;"><img src="' . $escape($qrUrl) . '" width="200" height="200" alt="QR Code Pix" '
                . 'style="display:inline-block;width:200px;height:200px;border:0;"></p>';
        }
        $code = (string) ($method === 'boleto' ? ($display['digitableLine'] ?? '') : ($display['copyPaste'] ?? ''));
        if ($code !== '') {
            $html .= '<p style="margin:0 0 6px;"><strong>' . ($method === 'boleto' ? 'Linha digitável' : 'Pix copia e cola') . '</strong></p>'
                . ($method === 'pix'
                    ? '<p style="margin:0 0 16px;padding:12px;background:#f6f8fb;border:1px solid #e1e7f0;border-radius:6px;font-family:monospace;word-break:break-all;overflow-wrap:anywhere;">'
                        . self::unlinkable($code, $escape) . '</p>'
                    : '<p style="margin:0 0 16px;padding:14px 12px;background:#f6f8fb;border:1px solid #e1e7f0;border-radius:6px;font-family:monospace;font-size:13px;text-align:center;word-break:normal;overflow-wrap:anywhere;">'
                        . $escape(self::digitableLine($code)) . '</p>');
        }
        // The Pagou boleto page opens without login; otherwise route readers through
        // the WHMCS login before the protected PDF endpoint.
        if ($method === 'boleto' && preg_match('#^https://#i', $boletoUrl) === 1) {
            // A table button keeps its size in Outlook; the icon is a hosted image because
            // Gmail and Outlook drop inline SVG.
            $icon = preg_match('#^https://#i', $boletoIconUrl) === 1
                ? '<img src="' . $escape($boletoIconUrl) . '" width="22" height="22" alt="" style="display:inline-block;width:22px;height:22px;border:0;vertical-align:middle;margin-right:10px;">'
                : '';
            $html .= '<table role="presentation" align="center" cellpadding="0" cellspacing="0" style="margin:20px auto 16px;"><tr>'
                . '<td style="background:#2856d9;border-radius:8px;text-align:center;">'
                . '<a href="' . $escape($boletoUrl) . '" style="display:inline-block;padding:14px 32px;font-family:Arial,sans-serif;font-size:16px;line-height:22px;font-weight:bold;color:#ffffff;text-decoration:none;">'
                . $icon . '<span style="vertical-align:middle;">Ver boleto</span></a></td></tr></table>';
        } elseif ($method === 'boleto' && $pdfUrl !== '' && preg_match('#^https?://#i', $invoiceUrl) === 1) {
            $html .= '<p style="margin:16px 0;"><a href="' . $escape($invoiceUrl) . '" style="display:inline-block;padding:11px 20px;background:#2856d9;border-radius:6px;color:#ffffff;text-decoration:none;font-weight:bold;">Acessar fatura</a></p>';
        }
        if (preg_match('#^https?://#i', $invoiceUrl) === 1) {
            $html .= '<p style="margin:12px 0 0;font-size:12px;color:#5d6878;">Consulte os dados e a situação atual do pagamento na <a href="' . $escape($invoiceUrl) . '" style="color:#2856d9;text-decoration:underline;">sua fatura</a>.</p>';
        }
        return $html . '</td></tr></table>';
    }

    /** The 47 digits of a boleto line in the grouping printed on the boleto itself. */
    private static function digitableLine(string $line): string
    {
        $digits = (string) preg_replace('/\D/', '', $line);
        if (strlen($digits) !== 47) {
            return $line;
        }

        return substr($digits, 0, 5) . '.' . substr($digits, 5, 5) . ' ' . substr($digits, 10, 5) . '.' . substr($digits, 15, 6) . ' '
            . substr($digits, 21, 5) . '.' . substr($digits, 26, 6) . ' ' . $digits[32] . ' ' . substr($digits, 33);
    }

    /**
     * Gmail turns the address inside a Pix payload into a broken link. Each part between
     * dots and slashes is its own element, so no text looks like an address, while a copy
     * still yields the exact code: elements add no characters, unlike invisible spacers.
     * @param callable(string): string $escape
     */
    private static function unlinkable(string $code, callable $escape): string
    {
        $parts = preg_split('#(?=[./])#', $code, -1, PREG_SPLIT_NO_EMPTY) ?: [$code];

        return implode('', array_map(static fn (string $part): string => '<span>' . $escape($part) . '</span>', $parts));
    }
}
