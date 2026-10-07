<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Configuration;

use PDO;

final class EncryptedCredentialStore
{
    private const KEY = 'api_key_ciphertext';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(string $credential): string
    {
        $credential = trim($credential);
        if ($credential === '' || strlen($credential) > 4096) {
            throw new \InvalidArgumentException('A credencial Pagou informada é inválida.');
        }
        if (!function_exists('encrypt')) {
            throw new \RuntimeException('A criptografia nativa do WHMCS não está disponível.');
        }
        $ciphertext = encrypt($credential);
        if (!is_string($ciphertext) || $ciphertext === '' || hash_equals($credential, $ciphertext)) {
            throw new \RuntimeException('O WHMCS não protegeu a credencial corretamente.');
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $update = $this->pdo->prepare(
            'UPDATE pagou_settings SET setting_value = :value, is_secret = 1, updated_at = :updated_at '
            . 'WHERE setting_key = :key'
        );
        $update->execute(['value' => $ciphertext, 'updated_at' => $now, 'key' => self::KEY]);
        if ($update->rowCount() === 0) {
            try {
                $insert = $this->pdo->prepare(
                    'INSERT INTO pagou_settings (setting_key, setting_value, is_secret, updated_at) '
                    . 'VALUES (:key, :value, 1, :updated_at)'
                );
                $insert->execute(['key' => self::KEY, 'value' => $ciphertext, 'updated_at' => $now]);
            } catch (\PDOException $exception) {
                if (!in_array((string) $exception->getCode(), ['19', '23000', '23505'], true)) {
                    throw $exception;
                }
                $update->execute(['value' => $ciphertext, 'updated_at' => $now, 'key' => self::KEY]);
            }
        }

        return CredentialRedactor::fingerprint($credential);
    }

    public function load(): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT setting_value FROM pagou_settings WHERE setting_key = :key AND is_secret = 1 LIMIT 1'
        );
        $statement->execute(['key' => self::KEY]);
        $ciphertext = $statement->fetchColumn();
        if (!is_string($ciphertext) || $ciphertext === '') {
            return null;
        }
        if (!function_exists('decrypt')) {
            throw new \RuntimeException('A descriptografia nativa do WHMCS não está disponível.');
        }
        $credential = decrypt($ciphertext);
        if (!is_string($credential) || trim($credential) === '') {
            throw new \RuntimeException('A credencial Pagou armazenada não pôde ser recuperada.');
        }

        return trim($credential);
    }

    public function configured(): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM pagou_settings WHERE setting_key = :key AND is_secret = 1 '
            . "AND setting_value IS NOT NULL AND setting_value <> '' LIMIT 1"
        );
        $statement->execute(['key' => self::KEY]);

        return $statement->fetchColumn() !== false;
    }
}
