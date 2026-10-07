<?php

declare(strict_types=1);

namespace WHMCS\Module;

abstract class AbstractWidget
{
    public function render($forceRefresh = false)
    {
        return $this->generateOutput($this->getData());
    }

    abstract public function getData();
    abstract public function generateOutput($data);
}

namespace WHMCS\User;

final class Admin
{
    public static ?self $current = null;
    public bool $isDisabled = false;
    public bool $finance = true;
    public bool $module = true;

    public static function getAuthenticatedUser(): ?self
    {
        return self::$current;
    }

    public function hasPermission(string $permission): bool
    {
        return $permission === 'View Income Totals' && $this->finance;
    }

    /** @return array<string,string> */
    public function getModulePermissions(): array
    {
        return $this->module ? ['pagou_payments' => 'Pagou para WHMCS'] : [];
    }
}
