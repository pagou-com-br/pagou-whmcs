<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('011', 'Create card transactions', [
    'CREATE TABLE IF NOT EXISTS pagou_card_transactions (id CHAR(36) NOT NULL PRIMARY KEY, attempt_id CHAR(36) NOT NULL, provider_transaction_id VARCHAR(128) NULL, token_reference VARCHAR(255) NULL, brand VARCHAR(32) NULL, last4 CHAR(4) NULL, installments SMALLINT UNSIGNED NOT NULL DEFAULT 1, authorization_code VARCHAR(128) NULL, three_ds_status VARCHAR(32) NULL, capture_status VARCHAR(32) NOT NULL, chargeback_status VARCHAR(32) NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1, UNIQUE KEY pagou_card_attempt_uq (attempt_id), UNIQUE KEY pagou_card_provider_uq (provider_transaction_id), KEY pagou_card_capture_idx (capture_status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_card_transactions (id TEXT PRIMARY KEY, attempt_id TEXT NOT NULL UNIQUE, provider_transaction_id TEXT NULL UNIQUE, token_reference TEXT NULL, brand TEXT NULL, last4 TEXT NULL, installments INTEGER NOT NULL DEFAULT 1, authorization_code TEXT NULL, three_ds_status TEXT NULL, capture_status TEXT NOT NULL, chargeback_status TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1)',
]);
