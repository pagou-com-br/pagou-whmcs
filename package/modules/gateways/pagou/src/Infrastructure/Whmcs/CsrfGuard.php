<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Whmcs;

use Closure;
use RuntimeException;

/** CSRF guard backed by a session callable, so request handlers stay testable. */
final class CsrfGuard
{
    /** @var Closure(string):mixed */
    private readonly Closure $get;

    /** @var Closure(string,mixed):void */
    private readonly Closure $set;

    /** @param callable(string):mixed $get @param callable(string,mixed):void $set */
    public function __construct(callable $get, callable $set, private readonly string $scope = 'pagou_whmcs')
    {
        $this->get = Closure::fromCallable($get);
        $this->set = Closure::fromCallable($set);
    }

    public function token(): string
    {
        $key = $this->key();
        $token = ($this->get)($key);
        if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        ($this->set)($key, $token);
        return $token;
    }

    public function assertValid(?string $submitted): void
    {
        $expected = ($this->get)($this->key());
        if (!is_string($submitted) || !is_string($expected) || !hash_equals($expected, $submitted)) {
            throw new RuntimeException('Invalid CSRF token.');
        }
    }

    private function key(): string
    {
        return $this->scope . '_csrf';
    }
}
