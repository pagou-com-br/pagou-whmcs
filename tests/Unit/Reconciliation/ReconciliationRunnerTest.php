<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Reconciliation;

use Pagou\Whmcs\Application\Reconciliation\ReconciliationCandidate;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationMode;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationPage;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationPriority;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationRequest;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationRunner;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationWindow;
use Pagou\Whmcs\Application\Reconciliation\RecoveryAction;
use Pagou\Whmcs\Application\Reconciliation\RecoveryResult;
use Pagou\Whmcs\Domain\UtcInstant;
use PHPUnit\Framework\TestCase;

final class ReconciliationRunnerTest extends TestCase
{
    public function testDryRunRecordsFindingWithoutRecoveringIt(): void
    {
        $store = new InMemoryFindingStore();
        $recovery = new RecordingRecoveryPort();
        $scanner = new TestScanner(
            'uncertain-operations',
            ReconciliationPriority::Critical,
            new ReconciliationPage([$this->candidate(RecoveryAction::RefreshOperation)], 'next-page', false),
        );

        $report = (new ReconciliationRunner([$scanner], $store, $recovery))->run($this->request(ReconciliationMode::DryRun));

        self::assertSame(0, $recovery->calls);
        self::assertCount(1, $store->findings);
        self::assertSame('next-page', $report->scanners[0]->nextCursor);
        self::assertSame(1, $report->scanners[0]->newFindings);
        self::assertArrayNotHasKey('next_cursor', $report->sanitized()['scanners'][0]);
    }

    public function testExecuteUsesRecoveryAndResolvesFinding(): void
    {
        $store = new InMemoryFindingStore();
        $recovery = new RecordingRecoveryPort(RecoveryResult::recovered());
        $scanner = new TestScanner(
            'inbox',
            ReconciliationPriority::High,
            new ReconciliationPage([$this->candidate(RecoveryAction::ReplayInbox)], null, true),
        );

        $report = (new ReconciliationRunner([$scanner], $store, $recovery))->run($this->request(ReconciliationMode::Execute));

        self::assertSame(1, $recovery->calls);
        self::assertSame('resolved', $store->findings[0]->status->value);
        self::assertSame(1, $report->scanners[0]->recovered);
    }

    public function testScannersRunInFinancialPriorityOrderAndReceiveCursorAndWindow(): void
    {
        $calls = [];
        $low = new TestScanner('payments', ReconciliationPriority::Low, new ReconciliationPage([], null, true), $calls);
        $high = new TestScanner('attempts', ReconciliationPriority::High, new ReconciliationPage([], null, true), $calls);
        $request = $this->request(ReconciliationMode::DryRun, ['attempts' => 'offset-7']);

        (new ReconciliationRunner([$low, $high], new InMemoryFindingStore(), new RecordingRecoveryPort()))
            ->run($request);

        self::assertSame(['attempts', 'payments'], $calls);
        self::assertSame('offset-7', $high->context?->cursor);
        self::assertSame(
            '2026-08-01T00:00:00.000000Z',
            (string) $high->context?->request->window->from,
        );
    }

    public function testDuplicateCandidateIsRecordedOnlyOnce(): void
    {
        $store = new InMemoryFindingStore();
        $scanner = new TestScanner(
            'attempts',
            ReconciliationPriority::Normal,
            new ReconciliationPage([$this->candidate()], null, true),
        );
        $runner = new ReconciliationRunner([$scanner], $store, new RecordingRecoveryPort());

        $runner->run($this->request(ReconciliationMode::DryRun));
        $second = $runner->run($this->request(ReconciliationMode::DryRun));

        self::assertCount(1, $store->findings);
        self::assertSame(1, $second->scanners[0]->deduplicatedFindings);
    }

    public function testScannerFailureDoesNotExposeItsMessageOrStopOtherScanners(): void
    {
        $failing = new ThrowingScanner('inbox', ReconciliationPriority::Critical);
        $healthy = new TestScanner(
            'payments',
            ReconciliationPriority::Normal,
            new ReconciliationPage([], null, true),
        );

        $report = (new ReconciliationRunner([$failing, $healthy], new InMemoryFindingStore(), new RecordingRecoveryPort()))
            ->run($this->request(ReconciliationMode::DryRun));

        self::assertSame('scanner_failure:RuntimeException', $report->scanners[0]->error);
        self::assertSame(0, $report->scanners[1]->observed);
        self::assertFalse($report->sanitized()['scanners'][0]['has_error'] === false);
    }

    private function candidate(RecoveryAction $action = RecoveryAction::None): ReconciliationCandidate
    {
        return new ReconciliationCandidate(
            'operation',
            'op-123',
            'uncertain_state',
            ReconciliationPriority::Critical,
            'Operation needs status refresh.',
            $action,
            ['retry_count' => 1],
        );
    }

    /** @param array<string, string> $cursors */
    private function request(ReconciliationMode $mode, array $cursors = []): ReconciliationRequest
    {
        return new ReconciliationRequest(
            $mode,
            new ReconciliationWindow(
                UtcInstant::fromString('2026-08-01T00:00:00Z'),
                UtcInstant::fromString('2026-08-02T00:00:00Z'),
            ),
            $cursors,
            10,
        );
    }
}
