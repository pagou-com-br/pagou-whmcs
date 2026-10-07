<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\Security;

final class Csrf
{
    public function assertValid(?string $token): void
    {
        if ($token === null || $token === '') {
            throw new \RuntimeException('Token de segurança ausente.');
        }

        if (function_exists('check_token')) {
            // WHMCS validates the request token from the submitted form.
            $_REQUEST['token'] = $token;
            check_token('WHMCS.admin.default');
            return;
        }

        if (!isset($_SESSION['tkval']) || !hash_equals((string) $_SESSION['tkval'], $token)) {
            throw new \RuntimeException('Token de segurança inválido.');
        }
    }

    public function field(): string
    {
        if (function_exists('generate_token')) {
            return '<input type="hidden" name="token" value="'
                . htmlspecialchars((string) generate_token('plain'), ENT_QUOTES, 'UTF-8') . '">';
        }

        return '<input type="hidden" name="token" value="' . htmlspecialchars((string) ($_SESSION['tkval'] ?? ''), ENT_QUOTES, 'UTF-8') . '">';
    }
}
