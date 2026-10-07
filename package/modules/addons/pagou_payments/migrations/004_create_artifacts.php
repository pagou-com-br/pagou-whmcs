<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('004', 'Create payment artifacts', [
    'CREATE TABLE IF NOT EXISTS pagou_payment_artifacts (id CHAR(36) NOT NULL PRIMARY KEY, attempt_id CHAR(36) NOT NULL, artifact_type VARCHAR(32) NOT NULL, content_hash CHAR(64) NULL, storage_key VARCHAR(255) NULL, content_type VARCHAR(128) NULL, content_length BIGINT UNSIGNED NULL, payload_json LONGTEXT NULL, expires_at_utc DATETIME(6) NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1, UNIQUE KEY pagou_artifact_attempt_type_uq (attempt_id, artifact_type), KEY pagou_artifact_expiry_idx (expires_at_utc)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_payment_artifacts (id TEXT PRIMARY KEY, attempt_id TEXT NOT NULL, artifact_type TEXT NOT NULL, content_hash TEXT NULL, storage_key TEXT NULL, content_type TEXT NULL, content_length INTEGER NULL, payload_json TEXT NULL, expires_at_utc TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, UNIQUE(attempt_id, artifact_type))',
]);
