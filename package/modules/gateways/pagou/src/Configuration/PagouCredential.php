<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Configuration;

/**
 * The single Pagou credential accepted by the module.
 *
 * PAGOU_API_KEY is intentionally an operational override: a value held by the
 * process environment wins over the value saved by WHMCS and is never exposed
 * through settings(), logs, diagnostics, or forms.
 */
final class PagouCredential
{
    private function __construct(private readonly string $value, private readonly string $source)
    {
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed>|null $environment
     */
    public static function fromSettings(array $settings, ?array $environment = null): self
    {
        $environment ??= $_ENV + $_SERVER;
        $environmentValue = $environment['PAGOU_API_KEY'] ?? getenv('PAGOU_API_KEY');
        if (is_string($environmentValue) && trim($environmentValue) !== '') {
            return new self(trim($environmentValue), 'environment');
        }

        foreach (['api_key', 'token_privado', 'private_token'] as $field) {
            $candidate = $settings[$field] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return new self(trim($candidate), 'whmcs');
            }
        }

        throw new \InvalidArgumentException('A credencial Pagou é obrigatória.');
    }

    public function value(): string
    {
        return $this->value;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function fingerprint(): string
    {
        return CredentialRedactor::fingerprint($this->value);
    }

    /** There is deliberately no accessor that returns this value for display. */
    public function masked(): string
    {
        return CredentialRedactor::mask($this->value);
    }
}
