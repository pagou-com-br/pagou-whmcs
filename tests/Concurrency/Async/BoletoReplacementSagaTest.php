<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Concurrency\Async;

use Pagou\Whmcs\Application\Async\BoletoReplacementSaga;
use Pagou\Whmcs\Application\Async\ReplacementSagaState;
use PHPUnit\Framework\TestCase;

final class BoletoReplacementSagaTest extends TestCase
{
    public function testUncertainCancellationRequiresManualReviewBeforeNewIssue(): void
    {
        $saga = new BoletoReplacementSaga();
        $saga->beginCancellation();
        $saga->markUncertain();
        self::assertSame(ReplacementSagaState::ManualReview, $saga->state);
        $this->expectException(\LogicException::class);
        $saga->requestReplacementIssue();
    }

    public function testConfirmedReplacementHasExplicitTransitions(): void
    {
        $saga = new BoletoReplacementSaga();
        $saga->beginCancellation();
        $saga->cancellationConfirmed();
        $saga->requestReplacementIssue();
        $saga->replacementIssued();
        $saga->complete();

        self::assertSame(ReplacementSagaState::Completed, $saga->state);
    }
}
