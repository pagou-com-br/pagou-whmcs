<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('013', 'Create remote card storage', [
    'CREATE TABLE IF NOT EXISTS pagou_card_customers (client_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, provider_customer_id CHAR(36) NOT NULL, document_fingerprint CHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1, UNIQUE KEY pagou_card_customer_provider_uq (provider_customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'CREATE TABLE IF NOT EXISTS pagou_card_remote_input_sessions (id CHAR(36) NOT NULL PRIMARY KEY, secret_hash CHAR(64) NOT NULL, workflow VARCHAR(16) NOT NULL, client_id BIGINT UNSIGNED NOT NULL, invoice_id BIGINT UNSIGNED NULL, pay_method_id BIGINT UNSIGNED NULL, amount_cents BIGINT NOT NULL DEFAULT 0, currency CHAR(3) NOT NULL DEFAULT \'BRL\', existing_reference VARCHAR(1024) NULL, status VARCHAR(24) NOT NULL, installments SMALLINT UNSIGNED NOT NULL DEFAULT 1, result_json LONGTEXT NULL, expires_at_utc DATETIME(6) NOT NULL, consumed_at_utc DATETIME(6) NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1, KEY pagou_card_session_expiry_idx (expires_at_utc, status), KEY pagou_card_session_client_idx (client_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_card_customers (client_id INTEGER PRIMARY KEY, provider_customer_id TEXT NOT NULL UNIQUE, document_fingerprint TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1)',
    'CREATE TABLE IF NOT EXISTS pagou_card_remote_input_sessions (id TEXT PRIMARY KEY, secret_hash TEXT NOT NULL, workflow TEXT NOT NULL, client_id INTEGER NOT NULL, invoice_id INTEGER NULL, pay_method_id INTEGER NULL, amount_cents INTEGER NOT NULL DEFAULT 0, currency TEXT NOT NULL DEFAULT \'BRL\', existing_reference TEXT NULL, status TEXT NOT NULL, installments INTEGER NOT NULL DEFAULT 1, result_json TEXT NULL, expires_at_utc TEXT NOT NULL, consumed_at_utc TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1)',
    'CREATE INDEX IF NOT EXISTS pagou_card_session_expiry_idx ON pagou_card_remote_input_sessions(expires_at_utc, status)',
    'CREATE INDEX IF NOT EXISTS pagou_card_session_client_idx ON pagou_card_remote_input_sessions(client_id, created_at)',
]);
