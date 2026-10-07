<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('015', 'Preserve payment identity history', [
    'CREATE TABLE IF NOT EXISTS pagou_issued_parties (attempt_id CHAR(36) PRIMARY KEY, name VARCHAR(200) NOT NULL, document VARCHAR(14) NOT NULL, pix_id VARCHAR(128) NOT NULL DEFAULT \'\', link_conflict SMALLINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'CREATE TABLE IF NOT EXISTS pagou_pix_payers (identity_key CHAR(64) PRIMARY KEY, remote_id VARCHAR(128) NOT NULL, transaction_id VARCHAR(128) NOT NULL, amount_cents BIGINT NOT NULL, name VARCHAR(200) NOT NULL, document VARCHAR(14) NOT NULL, e2e_id VARCHAR(128) NOT NULL, conflicted SMALLINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_issued_parties (attempt_id TEXT PRIMARY KEY, name TEXT NOT NULL, document TEXT NOT NULL, pix_id TEXT NOT NULL DEFAULT \'\', link_conflict INTEGER NOT NULL DEFAULT 0)',
    'CREATE TABLE IF NOT EXISTS pagou_pix_payers (identity_key TEXT PRIMARY KEY, remote_id TEXT NOT NULL, transaction_id TEXT NOT NULL, amount_cents INTEGER NOT NULL, name TEXT NOT NULL, document TEXT NOT NULL, e2e_id TEXT NOT NULL, conflicted INTEGER NOT NULL DEFAULT 0)',
]);
