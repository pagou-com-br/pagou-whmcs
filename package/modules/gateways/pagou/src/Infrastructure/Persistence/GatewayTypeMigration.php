<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Persistence;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * WHMCS stores the gateway type when a gateway is activated and never reads the module metadata
 * again. Pix and boleto were activated as "Bank", so the daily card charge task kept trying to
 * charge every open invoice on them after the metadata was fixed. "Invoices" is the type WHMCS
 * gives a gateway that only shows a payment link.
 */
final class GatewayTypeMigration implements ProceduralMigration
{
    private const FROM = 'Bank';
    private const TO = 'Invoices';

    /** @var callable(string): void|null */
    private $log;

    /**
     * @param list<string> $gateways
     * @param (callable(string): void)|null $log
     */
    public function __construct(
        private readonly string $migrationVersion,
        private readonly string $migrationDescription,
        private readonly array $gateways,
        ?callable $log = null,
    ) {
        if ($gateways === []) {
            throw new InvalidArgumentException('A gateway type migration must name its gateways.');
        }
        foreach ($gateways as $gateway) {
            if (preg_match('/^pagou_[a-z0-9_]+$/D', $gateway) !== 1) {
                throw new InvalidArgumentException('Gateway type migrations are restricted to module gateways.');
            }
        }
        $this->log = $log ?? (function_exists('logActivity') ? 'logActivity' : null);
    }

    public function version(): string
    {
        return $this->migrationVersion;
    }

    public function description(): string
    {
        return $this->migrationDescription;
    }

    /** @return list<string> */
    public function statements(string $driver): array
    {
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        return ["UPDATE tblpaymentgateways SET value = '" . self::TO . "' WHERE setting = 'type' AND value = '"
            . self::FROM . "' AND gateway IN ('" . implode("', '", $this->gateways) . "')"];
    }

    public function apply(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $statements = $this->statements($driver);
        if (!$this->hasGatewayTable($pdo, $driver)) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($this->gateways), '?'));
        $query = $pdo->prepare(
            "SELECT gateway, value FROM tblpaymentgateways WHERE setting = 'type' AND gateway IN ($placeholders) ORDER BY gateway"
        );
        $query->execute($this->gateways);
        $types = $query->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($types === []) {
            return;
        }
        $pdo->exec($statements[0]);

        $changes = [];
        foreach ($types as $gateway => $type) {
            $changes[] = $type === self::FROM
                ? $gateway . ' ' . self::FROM . ' para ' . self::TO
                : $gateway . ' ' . $type . ' sem alteração';
        }
        if ($this->log !== null) {
            ($this->log)('Pagou Payments: tipo dos gateways no WHMCS conferido para a cobrança automática diária: '
                . implode(', ', $changes) . '.');
        }
    }

    private function hasGatewayTable(PDO $pdo, string $driver): bool
    {
        $query = $driver === 'sqlite'
            ? "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'tblpaymentgateways'"
            : "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpaymentgateways'";

        return $pdo->query($query)->fetchColumn() !== false;
    }
}
