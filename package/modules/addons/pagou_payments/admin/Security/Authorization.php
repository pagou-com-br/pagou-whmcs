<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\Security;

final class Authorization
{
    /**
     * WHMCS enforces addon access before reaching the output callback. This
     * second check keeps destructive UI actions unavailable without a session.
     */
    public function canOperate(): bool
    {
        return isset($_SESSION['adminid']) && (int) $_SESSION['adminid'] > 0;
    }

    public function assertCanOperate(): void
    {
        if (!$this->canOperate()) {
            throw new \RuntimeException('Sessão administrativa obrigatória para esta operação.');
        }
    }
}
