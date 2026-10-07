<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Reconciliation;

use Pagou\Whmcs\Application\Reconciliation\ReconciliationFinding;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationRecoveryPort;
use Pagou\Whmcs\Application\Reconciliation\RecoveryResult;

final class RecordingRecoveryPort implements ReconciliationRecoveryPort
{
    public int $calls = 0;

    public function __construct(private readonly ?RecoveryResult $result = null)
    {
    }

    public function recover(ReconciliationFinding $finding): RecoveryResult
    {
        ++$this->calls;
        return $this->result ?? RecoveryResult::notRecovered();
    }
}
