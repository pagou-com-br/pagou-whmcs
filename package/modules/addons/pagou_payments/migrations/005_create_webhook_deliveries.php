<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('005', 'Create webhook deliveries', [
    'CREATE TABLE IF NOT EXISTS pagou_webhook_deliveries (id CHAR(36) NOT NULL PRIMARY KEY, event_key CHAR(64) NOT NULL, provider_event_id VARCHAR(128) NULL, event_type VARCHAR(128) NOT NULL, signature_valid TINYINT(1) NOT NULL, payload_json LONGTEXT NOT NULL, received_at_utc DATETIME(6) NOT NULL, processed_at_utc DATETIME(6) NULL, processing_status VARCHAR(32) NOT NULL, error_message TEXT NULL, attempt_id CHAR(36) NULL, UNIQUE KEY pagou_webhook_event_uq (event_key), KEY pagou_webhook_status_idx (processing_status, received_at_utc), KEY pagou_webhook_provider_idx (provider_event_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_webhook_deliveries (id TEXT PRIMARY KEY, event_key TEXT NOT NULL UNIQUE, provider_event_id TEXT NULL, event_type TEXT NOT NULL, signature_valid INTEGER NOT NULL, payload_json TEXT NOT NULL, received_at_utc TEXT NOT NULL, processed_at_utc TEXT NULL, processing_status TEXT NOT NULL, error_message TEXT NULL, attempt_id TEXT NULL)',
    'CREATE INDEX IF NOT EXISTS pagou_webhook_status_idx ON pagou_webhook_deliveries(processing_status, received_at_utc)',
]);
