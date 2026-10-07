<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('010', 'Create worker leases', [
    'CREATE TABLE IF NOT EXISTS pagou_leases (name VARCHAR(128) NOT NULL PRIMARY KEY, owner VARCHAR(128) NOT NULL, lease_until DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, KEY pagou_lease_expiry_idx (lease_until)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_leases (name TEXT NOT NULL PRIMARY KEY, owner TEXT NOT NULL, lease_until TEXT NOT NULL, updated_at TEXT NOT NULL)',
]);
