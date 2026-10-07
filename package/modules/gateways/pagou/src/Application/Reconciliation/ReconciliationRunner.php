<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

use InvalidArgumentException;
use Pagou\Whmcs\Domain\UtcInstant;
use Throwable;

/**
 * Runs one bounded page from each scanner. Cursor persistence belongs to the caller,
 * which makes scheduling, leases, and retries independent from this application service.
 */
final class ReconciliationRunner
{
    /** @var list<ReconciliationScanner> */
    private array $scanners;

    /** @param iterable<ReconciliationScanner> $scanners */
    public function __construct(
        iterable $scanners,
        private readonly ReconciliationFindingStore $findings,
        private readonly ReconciliationRecoveryPort $recovery,
    ) {
        $byId = [];
        foreach ($scanners as $scanner) {
            $id = trim($scanner->id());
            if ($id === '' || strlen($id) > 100) {
                throw new InvalidArgumentException(
                    'Reconciliation scanner ids must be non-empty and at most 100 characters.',
                );
            }
            if (isset($byId[$id])) {
                throw new InvalidArgumentException(sprintf('Duplicate reconciliation scanner id "%s".', $id));
            }
            $byId[$id] = $scanner;
        }

        uasort(
            $byId,
            static fn (ReconciliationScanner $left, ReconciliationScanner $right): int =>
                $left->priority()->value <=> $right->priority()->value
                ?: $left->id() <=> $right->id(),
        );
        $this->scanners = array_values($byId);
    }

    public function run(ReconciliationRequest $request): ReconciliationReport
    {
        $startedAt = UtcInstant::now();
        $reports = [];

        foreach ($this->scanners as $scanner) {
            $reports[] = $this->runScanner($scanner, $request);
        }

        return new ReconciliationReport($request->mode, $startedAt, UtcInstant::now(), $reports);
    }

    private function runScanner(ReconciliationScanner $scanner, ReconciliationRequest $request): ScannerReport
    {
        $observed = 0;
        $newFindings = 0;
        $deduplicated = 0;
        $recovered = 0;
        $needsReview = 0;
        $nextCursor = null;
        $exhausted = false;

        try {
            $context = new ReconciliationScanContext(
                $request,
                $scanner->id(),
                $request->cursors[$scanner->id()] ?? null,
            );
            $page = $scanner->scan($context);
            if (count($page->candidates) > $request->maxItemsPerScanner) {
                throw new \RuntimeException('Scanner exceeded the requested item limit.');
            }

            foreach ($page->candidates as $candidate) {
                ++$observed;
                $finding = ReconciliationFinding::open(
                    $scanner->id(),
                    $candidate,
                    UtcInstant::now(),
                );
                $upsert = $this->findings->upsert($finding);
                $finding = $upsert->finding;

                if ($upsert->created) {
                    ++$newFindings;
                } else {
                    ++$deduplicated;
                }

                if (
                    $request->mode !== ReconciliationMode::Execute
                    || $candidate->recoveryAction === RecoveryAction::None
                ) {
                    continue;
                }

                $result = $this->recovery->recover($finding);
                if ($result->recovered) {
                    $this->findings->replace($finding->resolved());
                    ++$recovered;
                    continue;
                }

                if ($result->needsReview) {
                    $this->findings->replace($finding->needsReview());
                    ++$needsReview;
                }
            }

            $nextCursor = $page->nextCursor;
            $exhausted = $page->exhausted;

            if (!$exhausted && $nextCursor === null) {
                throw new \RuntimeException('A non-exhausted scanner page must advance its cursor.');
            }
        } catch (Throwable $exception) {
            return new ScannerReport(
                $scanner->id(),
                $scanner->priority(),
                $observed,
                $newFindings,
                $deduplicated,
                $recovered,
                $needsReview,
                $nextCursor,
                false,
                $this->sanitizeError($exception),
            );
        }

        return new ScannerReport(
            $scanner->id(),
            $scanner->priority(),
            $observed,
            $newFindings,
            $deduplicated,
            $recovered,
            $needsReview,
            $nextCursor,
            $exhausted,
        );
    }

    private function sanitizeError(Throwable $exception): string
    {
        // Exception messages may contain provider payloads or credentials. The report keeps only a stable category.
        return 'scanner_failure:' . (new \ReflectionClass($exception))->getShortName();
    }
}
