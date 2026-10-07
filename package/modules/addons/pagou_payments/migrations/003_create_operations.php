<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('003', 'Create durable payment operations outbox', [
    'CREATE TABLE IF NOT EXISTS pagou_payment_operations (id CHAR(36) NOT NULL PRIMARY KEY, attempt_id CHAR(36) NULL, operation_type VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, deduplication_key CHAR(64) NOT NULL, idempotency_key CHAR(64) NULL, priority INT UNSIGNED NOT NULL, payload_json LONGTEXT NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, available_at DATETIME(6) NOT NULL, lease_token CHAR(64) NULL, lease_expires_at DATETIME(6) NULL, fencing_token BIGINT UNSIGNED NOT NULL DEFAULT 0, remote_id VARCHAR(128) NULL, request_json LONGTEXT NULL, response_json LONGTEXT NULL, error_code VARCHAR(128) NULL, error_message TEXT NULL, retry_after_utc DATETIME(6) NULL, started_at DATETIME(6) NULL, finished_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1, UNIQUE KEY pagou_operation_deduplication_uq (deduplication_key), UNIQUE KEY pagou_operation_idempotency_uq (idempotency_key), KEY pagou_operation_claim_idx (status, available_at, priority, created_at), KEY pagou_operation_lease_idx (lease_expires_at), KEY pagou_operation_attempt_idx (attempt_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_payment_operations (id TEXT PRIMARY KEY, attempt_id TEXT NULL, operation_type TEXT NOT NULL, status TEXT NOT NULL, deduplication_key TEXT NOT NULL UNIQUE, idempotency_key TEXT NULL UNIQUE, priority INTEGER NOT NULL, payload_json TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, available_at TEXT NOT NULL, lease_token TEXT NULL, lease_expires_at TEXT NULL, fencing_token INTEGER NOT NULL DEFAULT 0, remote_id TEXT NULL, request_json TEXT NULL, response_json TEXT NULL, error_code TEXT NULL, error_message TEXT NULL, retry_after_utc TEXT NULL, started_at TEXT NULL, finished_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1)',
    'CREATE INDEX IF NOT EXISTS pagou_operation_claim_idx ON pagou_payment_operations(status, available_at, priority, created_at)',
    'CREATE INDEX IF NOT EXISTS pagou_operation_lease_idx ON pagou_payment_operations(lease_expires_at)',
    'CREATE INDEX IF NOT EXISTS pagou_operation_attempt_idx ON pagou_payment_operations(attempt_id)',
]);
