<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('002', 'Create payment attempts', [
    'CREATE TABLE IF NOT EXISTS pagou_payment_attempts (id CHAR(36) NOT NULL PRIMARY KEY, invoice_id BIGINT UNSIGNED NOT NULL, client_id BIGINT UNSIGNED NOT NULL, gateway VARCHAR(32) NOT NULL, method VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, amount_cents BIGINT NOT NULL, currency CHAR(3) NOT NULL DEFAULT \'BRL\', remote_id VARCHAR(128) NULL, idempotency_key CHAR(64) NOT NULL, economic_key CHAR(64) NOT NULL, request_json LONGTEXT NULL, response_json LONGTEXT NULL, split_snapshot_json LONGTEXT NULL, occurred_at_utc DATETIME(6) NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1, UNIQUE KEY pagou_attempt_idempotency_uq (idempotency_key), UNIQUE KEY pagou_attempt_economic_uq (economic_key), UNIQUE KEY pagou_attempt_remote_uq (remote_id), KEY pagou_attempt_invoice_idx (invoice_id), KEY pagou_attempt_client_idx (client_id), KEY pagou_attempt_status_idx (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_payment_attempts (id TEXT PRIMARY KEY, invoice_id INTEGER NOT NULL, client_id INTEGER NOT NULL, gateway TEXT NOT NULL, method TEXT NOT NULL, status TEXT NOT NULL, amount_cents INTEGER NOT NULL, currency TEXT NOT NULL DEFAULT \'BRL\', remote_id TEXT NULL UNIQUE, idempotency_key TEXT NOT NULL UNIQUE, economic_key TEXT NOT NULL UNIQUE, request_json TEXT NULL, response_json TEXT NULL, split_snapshot_json TEXT NULL, occurred_at_utc TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1)',
    'CREATE INDEX IF NOT EXISTS pagou_attempt_invoice_idx ON pagou_payment_attempts(invoice_id)',
    'CREATE INDEX IF NOT EXISTS pagou_attempt_status_idx ON pagou_payment_attempts(status)',
]);
