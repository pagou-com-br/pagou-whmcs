<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Configuration;

/**
 * Capabilities are intentionally explicit. Split starts disabled and can only
 * be enabled after the module has observed the canonical Pagou capability.
 */
final class CapabilityRegistry
{
    /** @var array<string, bool> */
    private readonly array $capabilities;

    /** @param array<string, bool> $enabled */
    public function __construct(array $enabled = [])
    {
        $defaults = [
            'pix' => true,
            'pix_due_date' => true,
            'boleto' => true,
            'card' => false,
            'split_projection' => true,
            'split' => false,
            'split_card' => false,
        ];
        foreach ($enabled as $name => $value) {
            if (!array_key_exists($name, $defaults)) {
                throw new \InvalidArgumentException(sprintf('Capacidade Pagou desconhecida: %s.', $name));
            }
            $defaults[$name] = $value;
        }
        if ($defaults['split_card'] && !$defaults['split']) {
            throw new \InvalidArgumentException('Split no cartão exige Split habilitado.');
        }
        $this->capabilities = $defaults;
    }

    public function isEnabled(string $name): bool
    {
        if (!array_key_exists($name, $this->capabilities)) {
            throw new \InvalidArgumentException(sprintf('Capacidade Pagou desconhecida: %s.', $name));
        }
        return $this->capabilities[$name];
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        return $this->capabilities;
    }
}
