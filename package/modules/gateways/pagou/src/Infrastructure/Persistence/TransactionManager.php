<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Persistence;

use PDO;
use Throwable;

final class TransactionManager
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        $nested = $this->pdo->inTransaction();
        if (!$nested) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $operation();
            if (!$nested) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if (!$nested && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
