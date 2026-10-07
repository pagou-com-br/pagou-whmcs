<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('012', 'Create card refunds and disputes', [
    'CREATE TABLE IF NOT EXISTS pagou_card_refunds (id CHAR(36) NOT NULL PRIMARY KEY, card_transaction_id CHAR(36) NOT NULL, provider_refund_id VARCHAR(128) NULL, idempotency_key CHAR(64) NOT NULL, amount_cents BIGINT NOT NULL, status VARCHAR(32) NOT NULL, reason VARCHAR(255) NULL, requested_at_utc DATETIME(6) NOT NULL, completed_at_utc DATETIME(6) NULL, response_json LONGTEXT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1, UNIQUE KEY pagou_card_refund_idempotency_uq (idempotency_key), UNIQUE KEY pagou_card_refund_provider_uq (provider_refund_id), KEY pagou_card_refund_transaction_idx (card_transaction_id, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'CREATE TABLE IF NOT EXISTS pagou_card_disputes (id CHAR(36) NOT NULL PRIMARY KEY, card_transaction_id CHAR(36) NOT NULL, provider_dispute_id VARCHAR(128) NULL, status VARCHAR(32) NOT NULL, amount_cents BIGINT NOT NULL, reason_code VARCHAR(128) NULL, reason_text TEXT NULL, opened_at_utc DATETIME(6) NULL, due_at_utc DATETIME(6) NULL, closed_at_utc DATETIME(6) NULL, evidence_json LONGTEXT NULL, response_json LONGTEXT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1, UNIQUE KEY pagou_card_dispute_provider_uq (provider_dispute_id), KEY pagou_card_dispute_transaction_idx (card_transaction_id, status), KEY pagou_card_dispute_due_idx (due_at_utc)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_card_refunds (id TEXT PRIMARY KEY, card_transaction_id TEXT NOT NULL, provider_refund_id TEXT NULL UNIQUE, idempotency_key TEXT NOT NULL UNIQUE, amount_cents INTEGER NOT NULL, status TEXT NOT NULL, reason TEXT NULL, requested_at_utc TEXT NOT NULL, completed_at_utc TEXT NULL, response_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1)',
    'CREATE INDEX IF NOT EXISTS pagou_card_refund_transaction_idx ON pagou_card_refunds(card_transaction_id, status)',
    'CREATE TABLE IF NOT EXISTS pagou_card_disputes (id TEXT PRIMARY KEY, card_transaction_id TEXT NOT NULL, provider_dispute_id TEXT NULL UNIQUE, status TEXT NOT NULL, amount_cents INTEGER NOT NULL, reason_code TEXT NULL, reason_text TEXT NULL, opened_at_utc TEXT NULL, due_at_utc TEXT NULL, closed_at_utc TEXT NULL, evidence_json TEXT NULL, response_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1)',
    'CREATE INDEX IF NOT EXISTS pagou_card_dispute_transaction_idx ON pagou_card_disputes(card_transaction_id, status)',
]);
