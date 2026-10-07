<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('006', 'Create immutable ledger entries', [
    'CREATE TABLE IF NOT EXISTS pagou_ledger_entries (id CHAR(36) NOT NULL PRIMARY KEY, idempotency_key CHAR(64) NOT NULL, invoice_id BIGINT UNSIGNED NOT NULL, client_id BIGINT UNSIGNED NOT NULL, attempt_id CHAR(36) NULL, entry_type VARCHAR(32) NOT NULL, amount_cents BIGINT NOT NULL, currency CHAR(3) NOT NULL DEFAULT \'BRL\', payment_at_utc DATETIME(6) NULL, effective_at_utc DATETIME(6) NOT NULL, metadata_json LONGTEXT NULL, created_at DATETIME(6) NOT NULL, UNIQUE KEY pagou_ledger_idempotency_uq (idempotency_key), KEY pagou_ledger_invoice_idx (invoice_id, effective_at_utc), KEY pagou_ledger_client_idx (client_id, effective_at_utc)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_ledger_entries (id TEXT PRIMARY KEY, idempotency_key TEXT NOT NULL UNIQUE, invoice_id INTEGER NOT NULL, client_id INTEGER NOT NULL, attempt_id TEXT NULL, entry_type TEXT NOT NULL, amount_cents INTEGER NOT NULL, currency TEXT NOT NULL DEFAULT \'BRL\', payment_at_utc TEXT NULL, effective_at_utc TEXT NOT NULL, metadata_json TEXT NULL, created_at TEXT NOT NULL)',
    'CREATE INDEX IF NOT EXISTS pagou_ledger_invoice_idx ON pagou_ledger_entries(invoice_id, effective_at_utc)',
]);
