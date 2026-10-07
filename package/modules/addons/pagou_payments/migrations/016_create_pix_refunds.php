<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

$columns = 'id VARCHAR(36) PRIMARY KEY, attempt_id VARCHAR(36) NOT NULL UNIQUE, invoice_id BIGINT NOT NULL, client_id BIGINT NOT NULL, remote_id VARCHAR(128) NOT NULL UNIQUE, original_transaction_id VARCHAR(128) NOT NULL, amount_cents BIGINT NOT NULL, receipt_cents BIGINT NOT NULL, status VARCHAR(32) NOT NULL, provider_refund_id VARCHAR(128) NULL UNIQUE, actor_id BIGINT NOT NULL, native_dispatch_at VARCHAR(26) NULL, requested_at VARCHAR(26) NOT NULL, confirmed_at VARCHAR(26) NULL, updated_at VARCHAR(26) NOT NULL, error_code VARCHAR(64) NULL';

return new AbstractMigration('016', 'Track confirmed Pix refunds and native application', [
    'CREATE TABLE IF NOT EXISTS pagou_pix_refunds (' . $columns . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_pix_refunds (' . $columns . ')',
]);
