<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;
use PDOException;

/** Reads and writes non-secret operational rows of pagou_settings; secret rows are never read or changed. */
final class OperationalSettings
{
    private const MAX_VALUE_BYTES = 65535;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key): ?string
    {
        $this->assertKey($key);
        $statement = $this->pdo->prepare(
            'SELECT setting_value FROM pagou_settings WHERE setting_key = :key AND is_secret = 0 LIMIT 1'
        );
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        $this->assertKey($key);
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            throw new \InvalidArgumentException('A configuração operacional informada é inválida.');
        }
        $parameters = ['key' => $key, 'value' => $value, 'updated_at' => gmdate('Y-m-d H:i:s.u')];
        $update = $this->pdo->prepare(
            'UPDATE pagou_settings SET setting_value = :value, is_secret = 0, updated_at = :updated_at '
            . 'WHERE setting_key = :key AND is_secret = 0'
        );
        $update->execute($parameters);
        if ($update->rowCount() > 0) {
            return;
        }
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO pagou_settings (setting_key, setting_value, is_secret, updated_at) '
                . 'VALUES (:key, :value, 0, :updated_at)'
            );
            $insert->execute($parameters);
        } catch (PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['19', '23000', '23505'], true)) {
                throw $exception;
            }
            // A concurrent writer created the row; a secret row with this key stays untouched.
            $update->execute($parameters);
        }
    }

    private function assertKey(string $key): void
    {
        if (preg_match('/^[a-z0-9_]{3,64}$/', $key) !== 1) {
            throw new \InvalidArgumentException('A configuração operacional informada é inválida.');
        }
    }
}
