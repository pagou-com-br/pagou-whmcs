<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Persistence;

use PDO;

/** A migration that inspects the database before changing it, instead of running fixed statements. */
interface ProceduralMigration extends Migration
{
    public function apply(PDO $pdo): void;
}
