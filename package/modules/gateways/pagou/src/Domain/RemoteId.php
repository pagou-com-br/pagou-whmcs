<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

use InvalidArgumentException;

/** Opaque identity allocated by Pagou. It must never be inferred or normalized. */
final class RemoteId extends Identifier
{
    public static function fromString(string $value): self
    {
        $value = trim($value);
        if (strlen($value) > 255 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/', $value)) {
            throw new InvalidArgumentException('Remote ID has an invalid format.');
        }

        return new self($value);
    }
}
