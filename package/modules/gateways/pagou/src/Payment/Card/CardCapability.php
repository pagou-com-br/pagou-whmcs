<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card;

/** Card acceptance remains disabled until the approved hosted-input library is present. */
final class CardCapability
{
    public function __construct(public readonly bool $enabled, public readonly string $reason)
    {
    }

    /** @param list<string> $approvedClasses */
    public static function fromInstalledLibraries(array $approvedClasses): self
    {
        foreach ($approvedClasses as $class) {
            if (class_exists($class)) {
                return new self(true, 'Approved card library available.');
            }
        }
        return new self(false, 'Card acceptance is disabled because no approved hosted-input library is installed.');
    }

    public function assertEnabled(): void
    {
        if (!$this->enabled) {
            throw new \LogicException($this->reason);
        }
    }
}
