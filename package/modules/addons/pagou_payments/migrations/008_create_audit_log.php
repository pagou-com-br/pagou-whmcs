<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;

return new AbstractMigration('008', 'Create audit log', [
    'CREATE TABLE IF NOT EXISTS pagou_audit_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, actor_type VARCHAR(32) NOT NULL, actor_id VARCHAR(128) NULL, action VARCHAR(128) NOT NULL, subject_type VARCHAR(64) NOT NULL, subject_id VARCHAR(128) NULL, correlation_id CHAR(36) NULL, ip_address VARCHAR(45) NULL, metadata_json LONGTEXT NULL, occurred_at_utc DATETIME(6) NOT NULL, KEY pagou_audit_subject_idx (subject_type, subject_id), KEY pagou_audit_correlation_idx (correlation_id), KEY pagou_audit_occurred_idx (occurred_at_utc)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], [
    'CREATE TABLE IF NOT EXISTS pagou_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_type TEXT NOT NULL, actor_id TEXT NULL, action TEXT NOT NULL, subject_type TEXT NOT NULL, subject_id TEXT NULL, correlation_id TEXT NULL, ip_address TEXT NULL, metadata_json TEXT NULL, occurred_at_utc TEXT NOT NULL)',
    'CREATE INDEX IF NOT EXISTS pagou_audit_subject_idx ON pagou_audit_log(subject_type, subject_id)',
]);
