<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\IndexMigration;

return new IndexMigration('014', 'Add reporting and recent activity indexes', [
    'pagou_ledger_payment_date_idx' => ['table' => 'pagou_ledger_entries', 'columns' => ['entry_type', 'currency', 'payment_at_utc']],
    'pagou_attempt_created_idx' => ['table' => 'pagou_payment_attempts', 'columns' => ['created_at']],
    'pagou_attempt_updated_idx' => ['table' => 'pagou_payment_attempts', 'columns' => ['updated_at']],
    'pagou_operation_updated_idx' => ['table' => 'pagou_payment_operations', 'columns' => ['updated_at']],
    'pagou_webhook_received_idx' => ['table' => 'pagou_webhook_deliveries', 'columns' => ['received_at_utc']],
]);
