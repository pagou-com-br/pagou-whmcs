<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Domain;

use Pagou\Whmcs\Domain\CardPaymentState;
use Pagou\Whmcs\Domain\CardPaymentStateMachine;
use Pagou\Whmcs\Domain\PaymentAttemptState;
use Pagou\Whmcs\Domain\PaymentAttemptStateMachine;
use Pagou\Whmcs\Domain\TransitionNotAllowed;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase
{
    public function testAnUncertainAttemptCanBeReconciledToPaid(): void
    {
        self::assertSame(
            PaymentAttemptState::Paid,
            PaymentAttemptStateMachine::transition(PaymentAttemptState::Uncertain, PaymentAttemptState::Paid),
        );
    }

    public function testTerminalCardPaymentCannotReturnToAuthorization(): void
    {
        $this->expectException(TransitionNotAllowed::class);
        CardPaymentStateMachine::transition(CardPaymentState::Captured, CardPaymentState::Authorized);
    }
}
