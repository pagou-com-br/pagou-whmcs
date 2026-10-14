<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('017', 'Record the notices sent to customers about a charge not issued', [
    'CREATE TABLE IF NOT EXISTS pagou_customer_notices (notice_key CHAR(64) NOT NULL PRIMARY KEY, invoice_id BIGINT UNSIGNED NOT NULL, attempt_id CHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, KEY pagou_notice_invoice_idx (invoice_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_customer_notices (notice_key TEXT PRIMARY KEY, invoice_id INTEGER NOT NULL, attempt_id TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
]);
