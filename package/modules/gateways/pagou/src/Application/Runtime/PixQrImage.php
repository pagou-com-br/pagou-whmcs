<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;

/** The QR Code image of a Pix charge that can still be paid, as Pagou issued it. */
final class PixQrImage
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{type:string,data:string}|null */
    public function find(string $attemptId, ?int $now = null): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.method, a.status, r.display_json FROM pagou_payment_attempts a '
            . 'LEFT JOIN pagou_payment_read_models r ON r.attempt_id = a.id WHERE a.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $attemptId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['method'] !== 'pix' || !in_array((string) $row['status'], ['ready', 'pending', 'active'], true)) {
            return null;
        }
        $display = json_decode((string) ($row['display_json'] ?? ''), true);
        $closed = [...PaymentAttemptStore::CLOSED_STATES, ...PaymentAttemptStore::RESULT_STATES];
        if (!is_array($display) || in_array((string) ($display['state'] ?? ''), $closed, true)) {
            return null;
        }
        // Same rule as the Pix PDF: missing validity is not proof that the code remains payable.
        $expiry = strtotime((string) ($display['pdfValidUntil'] ?? $display['expiresAt'] ?? ''));
        if ($expiry === false || $expiry <= ($now ?? time())) {
            return null;
        }
        if (preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=\r\n]+)$#D', (string) ($display['qrCodeImageUrl'] ?? ''), $match) !== 1) {
            return null;
        }
        $data = base64_decode($match[2], true);
        $signature = $match[1] === 'png' ? "\x89PNG\r\n\x1a\n" : "\xFF\xD8\xFF";
        if ($data === false || strlen($data) > 512000 || !str_starts_with($data, $signature)) {
            return null;
        }

        return ['type' => 'image/' . $match[1], 'data' => $data];
    }
}
