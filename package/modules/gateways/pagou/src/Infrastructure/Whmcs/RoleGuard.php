<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Whmcs;

use RuntimeException;

final class RoleGuard
{
    /** @param list<string> $roles */
    public function assertAnyRole(array $roles, string ...$acceptedRoles): void
    {
        $normalised = array_map(static fn (string $role): string => strtolower(trim($role)), $roles);
        foreach ($acceptedRoles as $role) {
            if (in_array(strtolower($role), $normalised, true)) {
                return;
            }
        }

        throw new RuntimeException('The administrator does not have permission for this operation.');
    }
}
