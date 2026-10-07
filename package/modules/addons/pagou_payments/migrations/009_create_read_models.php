<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('009', 'Create payment read models', [
    'CREATE TABLE IF NOT EXISTS pagou_payment_read_models (attempt_id CHAR(36) NOT NULL PRIMARY KEY, invoice_id BIGINT UNSIGNED NOT NULL, client_id BIGINT UNSIGNED NOT NULL, method VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, amount_cents BIGINT NOT NULL, remote_id VARCHAR(128) NULL, display_json LONGTEXT NOT NULL, refreshed_at_utc DATETIME(6) NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1, KEY pagou_read_invoice_idx (invoice_id), KEY pagou_read_client_idx (client_id), KEY pagou_read_status_idx (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'CREATE TABLE IF NOT EXISTS pagou_invoice_deliveries (delivery_key CHAR(64) NOT NULL PRIMARY KEY, invoice_id BIGINT UNSIGNED NOT NULL, attempt_id CHAR(36) NOT NULL, status VARCHAR(32) NOT NULL, attach_pdf TINYINT(1) NOT NULL DEFAULT 1, sent_at_utc DATETIME(6) NULL, error_message TEXT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, KEY pagou_delivery_invoice_idx (invoice_id), KEY pagou_delivery_status_idx (status, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'CREATE TABLE IF NOT EXISTS pagou_telemetry_consent (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, status VARCHAR(32) NOT NULL, policy_version VARCHAR(32) NOT NULL, decided_at_utc DATETIME(6) NOT NULL, actor_id BIGINT UNSIGNED NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'CREATE TABLE IF NOT EXISTS pagou_telemetry_jobs (id CHAR(36) NOT NULL PRIMARY KEY, payload_json LONGTEXT NOT NULL, status VARCHAR(32) NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, available_at_utc DATETIME(6) NOT NULL, expires_at_utc DATETIME(6) NOT NULL, created_at DATETIME(6) NOT NULL, KEY pagou_telemetry_claim_idx (status, available_at_utc), KEY pagou_telemetry_expiry_idx (expires_at_utc)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'CREATE TABLE IF NOT EXISTS pagou_telemetry_installation (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, installation_uuid CHAR(36) NOT NULL, created_at DATETIME(6) NOT NULL, UNIQUE KEY pagou_telemetry_installation_uq (installation_uuid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_payment_read_models (attempt_id TEXT PRIMARY KEY, invoice_id INTEGER NOT NULL, client_id INTEGER NOT NULL, method TEXT NOT NULL, status TEXT NOT NULL, amount_cents INTEGER NOT NULL, remote_id TEXT NULL, display_json TEXT NOT NULL, refreshed_at_utc TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1)',
    'CREATE INDEX IF NOT EXISTS pagou_read_invoice_idx ON pagou_payment_read_models(invoice_id)',
    'CREATE TABLE IF NOT EXISTS pagou_invoice_deliveries (delivery_key TEXT PRIMARY KEY, invoice_id INTEGER NOT NULL, attempt_id TEXT NOT NULL, status TEXT NOT NULL, attach_pdf INTEGER NOT NULL DEFAULT 1, sent_at_utc TEXT NULL, error_message TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
    'CREATE INDEX IF NOT EXISTS pagou_delivery_invoice_idx ON pagou_invoice_deliveries(invoice_id)',
    'CREATE TABLE IF NOT EXISTS pagou_telemetry_consent (id INTEGER PRIMARY KEY, status TEXT NOT NULL, policy_version TEXT NOT NULL, decided_at_utc TEXT NOT NULL, actor_id INTEGER NULL)',
    'CREATE TABLE IF NOT EXISTS pagou_telemetry_jobs (id TEXT PRIMARY KEY, payload_json TEXT NOT NULL, status TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, available_at_utc TEXT NOT NULL, expires_at_utc TEXT NOT NULL, created_at TEXT NOT NULL)',
    'CREATE TABLE IF NOT EXISTS pagou_telemetry_installation (id INTEGER PRIMARY KEY, installation_uuid TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL)',
]);
