<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

use DomainException;

final class TransitionNotAllowed extends DomainException
{
    public static function between(string $from, string $to): self
    {
        return new self(sprintf('Transition from "%s" to "%s" is not allowed.', $from, $to));
    }
}
