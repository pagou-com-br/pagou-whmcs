<?php

declare(strict_types=1);

namespace Pagou\Whmcs\InvoicePdf;

use PDO;
use Pagou\Whmcs\Configuration\CentralSettingsStore;
use Pagou\Whmcs\Application\Runtime\PaymentAttemptStore;
use Pagou\Whmcs\Domain\Money;
use Pagou\Whmcs\Infrastructure\Whmcs\PrivateStorage;

/** Reads local payment evidence only. No issuance, fees, cancellation or network calls. */
final class DocumentSelector
{
    /** @param callable(string,array<string,mixed>):array<string,mixed> $localApi */
    public function __construct(
        private readonly PDO $pdo,
        private readonly PrivateStorage $storage,
        private readonly mixed $localApi,
    ) {
    }

    public function select(int $invoiceId, string $gateway): ?PaymentDocument
    {
        if ($invoiceId < 1 || !in_array($gateway, ['pagou_pix', 'pagou_boleto'], true)) {
            return null;
        }
        $method = substr($gateway, 6);
        $settings = (new CentralSettingsStore($this->pdo))->values();
        if (
            $settings[$method . '_email_details'] !== '1'
            || ($method === 'boleto' && $settings['boleto_email_pdf_mode'] !== 'attach')
        ) {
            return null;
        }
        $invoice = ($this->localApi)('GetInvoice', ['invoiceid' => $invoiceId]);
        if (
            ($invoice['result'] ?? '') !== 'success' || ($invoice['status'] ?? '') !== 'Unpaid'
            || ($invoice['paymentmethod'] ?? '') !== $gateway
        ) {
            return null;
        }
        // The document of the invoice's method, even with charges of other methods still open.
        $attempt = (new PaymentAttemptStore($this->pdo))->latestForInvoice($invoiceId, $method);
        $balance = Money::fromDecimal((string) ($invoice['balance'] ?? '0'))->centavos();
        if (
            $attempt === null || $attempt['method'] !== $method || !in_array($attempt['status'], $method === 'pix' ? ['ready', 'pending'] : ['ready'], true)
            || (int) $attempt['client_id'] !== (int) ($invoice['userid'] ?? 0)
            || (int) $attempt['amount_cents'] !== $balance || $balance < 1 || $attempt['currency'] !== 'BRL'
        ) {
            return null;
        }
        $request = json_decode((string) ($attempt['request_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($request) || ($request['due_date'] ?? '') !== ($invoice['duedate'] ?? '')) {
            return null;
        }
        if ($method === 'boleto') {
            $response = json_decode((string) ($attempt['response_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
            $key = is_array($response) ? ($response['artifacts']['local_pdf_key'] ?? null) : null;
            if (!is_string($key) || !str_starts_with($key, 'boleto/')) {
                return null;
            }
            $path = $this->storage->path($key);
            if (!is_file($path) || filesize($path) > 5242880) {
                return null;
            }
            $pdf = $this->storage->read($key);
            return str_starts_with($pdf, '%PDF-') ? new PaymentDocument($invoiceId, $method, $balance, $pdf) : null;
        }
        $query = $this->pdo->prepare('SELECT display_json FROM pagou_payment_read_models WHERE attempt_id = :id LIMIT 1');
        $query->execute(['id' => $attempt['id']]);
        $display = json_decode((string) ($query->fetchColumn() ?: '{}'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($display)) {
            return null;
        }
        $payload = (string) ($display['copyPaste'] ?? '');
        $expiry = (string) ($display['pdfValidUntil'] ?? $display['expiresAt'] ?? '');
        // Missing validity is not proof that a QR remains payable.
        if (!self::validPix($payload) || $expiry === '' || strtotime($expiry) === false || strtotime($expiry) <= time()) {
            return null;
        }
        $validUntil = (new \DateTimeImmutable($expiry))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))
            ->format('d/m/Y H:i') . ' (São Paulo)';
        return new PaymentDocument($invoiceId, $method, $balance, $payload, $validUntil);
    }

    public static function validPix(string $payload): bool
    {
        if (
            strlen($payload) > 2048 || !str_starts_with($payload, '000201')
            || stripos($payload, 'br.gov.bcb.pix') === false || preg_match('/6304[0-9A-Fa-f]{4}\z/', $payload) !== 1
        ) {
            return false;
        }
        $crc = 0xffff;
        foreach (str_split(substr($payload, 0, -4)) as $character) {
            $crc ^= ord($character) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = (($crc & 0x8000) !== 0 ? ($crc << 1) ^ 0x1021 : $crc << 1) & 0xffff;
            }
        }
        return strtoupper(substr($payload, -4)) === sprintf('%04X', $crc);
    }
}
