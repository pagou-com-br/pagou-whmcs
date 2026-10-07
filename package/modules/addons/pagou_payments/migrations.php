<?php

/** @return list<\Pagou\Whmcs\Infrastructure\Persistence\Migration> */

declare(strict_types=1);

return [
    require __DIR__ . '/migrations/001_create_module_settings.php',
    require __DIR__ . '/migrations/002_create_payment_attempts.php',
    require __DIR__ . '/migrations/003_create_operations.php',
    require __DIR__ . '/migrations/004_create_artifacts.php',
    require __DIR__ . '/migrations/005_create_webhook_deliveries.php',
    require __DIR__ . '/migrations/006_create_ledger_entries.php',
    require __DIR__ . '/migrations/007_create_reconciliation_findings.php',
    require __DIR__ . '/migrations/008_create_audit_log.php',
    require __DIR__ . '/migrations/009_create_read_models.php',
    require __DIR__ . '/migrations/010_create_leases.php',
    require __DIR__ . '/migrations/011_create_card_transactions.php',
    require __DIR__ . '/migrations/012_create_card_refunds_and_disputes.php',
    require __DIR__ . '/migrations/013_create_remote_card_storage.php',
    require __DIR__ . '/migrations/014_add_reporting_indexes.php',
    require __DIR__ . '/migrations/015_create_payment_parties.php',
    require __DIR__ . '/migrations/016_create_pix_refunds.php',
];
