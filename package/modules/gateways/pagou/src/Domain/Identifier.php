<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

use InvalidArgumentException;
use Stringable;

/** @internal Shared validation for opaque, application-owned identifiers. */
abstract class Identifier implements Stringable
{
    final protected function __construct(protected readonly string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(static::class . ' cannot be empty.');
        }
    }

    final public function value(): string
    {
        return $this->value;
    }

    final public function equals(self $other): bool
    {
        return static::class === $other::class && hash_equals($this->value, $other->value);
    }

    final public function __toString(): string
    {
        return $this->value;
    }
}
