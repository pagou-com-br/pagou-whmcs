<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Persistence;

use PDO;

final class LeaseRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function acquire(string $name, string $owner, int $seconds): bool
    {
        $until = gmdate('Y-m-d H:i:s', time() + $seconds);
        $now = gmdate('Y-m-d H:i:s');
        try {
            $query = $this->pdo->prepare('INSERT INTO pagou_leases (name, owner, lease_until, updated_at) VALUES (:name, :owner, :until, :now)');
            $query->execute(['name' => $name, 'owner' => $owner, 'until' => $until, 'now' => $now]);
            return true;
        } catch (\PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }
        }
        $query = $this->pdo->prepare('UPDATE pagou_leases SET owner = :owner, lease_until = :until, updated_at = :updated WHERE name = :name AND lease_until < :expired');
        $query->execute(['owner' => $owner, 'until' => $until, 'updated' => $now, 'name' => $name, 'expired' => $now]);
        return $query->rowCount() === 1;
    }

    /**
     * Moves the end of a lease this owner still holds to the given Unix time, never earlier than now.
     * A lease another owner already took over is left alone.
     */
    public function holdUntil(string $name, string $owner, int $until): bool
    {
        $query = $this->pdo->prepare('UPDATE pagou_leases SET lease_until = :until, updated_at = :now WHERE name = :name AND owner = :owner');
        $query->execute(['until' => gmdate('Y-m-d H:i:s', max($until, time())), 'now' => gmdate('Y-m-d H:i:s'), 'name' => $name, 'owner' => $owner]);
        return $query->rowCount() === 1;
    }

    public function release(string $name, string $owner): bool
    {
        $query = $this->pdo->prepare('DELETE FROM pagou_leases WHERE name = :name AND owner = :owner');
        $query->execute(['name' => $name, 'owner' => $owner]);
        return $query->rowCount() === 1;
    }
}
