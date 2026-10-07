<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('001', 'Create module settings', [
    'CREATE TABLE IF NOT EXISTS pagou_settings (setting_key VARCHAR(100) NOT NULL PRIMARY KEY, setting_value LONGTEXT NULL, is_secret TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME(6) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_settings (setting_key TEXT NOT NULL PRIMARY KEY, setting_value TEXT NULL, is_secret INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL)',
]);
