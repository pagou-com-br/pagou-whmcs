<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('007', 'Create reconciliation findings', [
    'CREATE TABLE IF NOT EXISTS pagou_reconciliation_findings (id CHAR(36) NOT NULL PRIMARY KEY, finding_key CHAR(64) NOT NULL, attempt_id CHAR(36) NULL, severity VARCHAR(16) NOT NULL, finding_type VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, details_json LONGTEXT NOT NULL, detected_at_utc DATETIME(6) NOT NULL, resolved_at_utc DATETIME(6) NULL, resolved_by BIGINT UNSIGNED NULL, resolution_json LONGTEXT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1, UNIQUE KEY pagou_finding_key_uq (finding_key), KEY pagou_finding_status_idx (status, severity, detected_at_utc)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_reconciliation_findings (id TEXT PRIMARY KEY, finding_key TEXT NOT NULL UNIQUE, attempt_id TEXT NULL, severity TEXT NOT NULL, finding_type TEXT NOT NULL, status TEXT NOT NULL, details_json TEXT NOT NULL, detected_at_utc TEXT NOT NULL, resolved_at_utc TEXT NULL, resolved_by INTEGER NULL, resolution_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1)',
    'CREATE INDEX IF NOT EXISTS pagou_finding_status_idx ON pagou_reconciliation_findings(status, severity, detected_at_utc)',
]);
