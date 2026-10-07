<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Reconciliation;

use InvalidArgumentException;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationCandidate;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationMode;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationPriority;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationRequest;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationWindow;
use Pagou\Whmcs\Application\Reconciliation\RecoveryAction;
use Pagou\Whmcs\Domain\UtcInstant;
use PHPUnit\Framework\TestCase;

final class ReconciliationContractsTest extends TestCase
{
    public function testWindowRejectsInvertedRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReconciliationWindow(
            UtcInstant::fromString('2026-08-03T00:00:00Z'),
            UtcInstant::fromString('2026-08-02T00:00:00Z'),
        );
    }

    public function testFingerprintIsStableAndDoesNotDependOnHumanSummary(): void
    {
        $first = new ReconciliationCandidate(
            'payment',
            'p-100',
            'unapplied',
            ReconciliationPriority::High,
            'First wording.',
            RecoveryAction::ApplyPayment,
        );
        $second = new ReconciliationCandidate(
            'payment',
            'p-100',
            'unapplied',
            ReconciliationPriority::High,
            'Different wording.',
            RecoveryAction::ApplyPayment,
        );

        self::assertSame($first->fingerprint('payments'), $second->fingerprint('payments'));
    }

    public function testRequestCapsScannerBatchSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReconciliationRequest(
            ReconciliationMode::DryRun,
            new ReconciliationWindow(
                UtcInstant::fromString('2026-08-01T00:00:00Z'),
                UtcInstant::fromString('2026-08-02T00:00:00Z'),
            ),
            [],
            1001,
        );
    }
}
