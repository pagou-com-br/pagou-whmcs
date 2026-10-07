<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\Http;

final class AdminRequest
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     */
    public function __construct(
        private readonly array $query,
        private readonly array $post,
        private readonly array $server,
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_SERVER);
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function page(): string
    {
        $page = (string) ($this->query['view'] ?? $this->post['view'] ?? 'dashboard');
        return preg_match('/^[a-z-]+$/', $page) === 1 ? $page : 'dashboard';
    }

    public function action(): ?string
    {
        $action = $this->post['action'] ?? null;
        return is_string($action) && preg_match('/^[a-z-]+$/', $action) === 1 ? $action : null;
    }

    public function postString(string $key): ?string
    {
        $value = $this->post[$key] ?? null;
        return is_scalar($value) ? trim((string) $value) : null;
    }

    public function queryString(string $key): ?string
    {
        $value = $this->query[$key] ?? null;
        return is_scalar($value) ? trim((string) $value) : null;
    }

    public function postInt(string $key): ?int
    {
        $value = $this->post[$key] ?? null;
        if (!is_scalar($value) || preg_match('/^[1-9]\d*$/', (string) $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
