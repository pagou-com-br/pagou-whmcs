<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Application;

final class PdfFetchPolicy
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly array $allowedHosts,
        private readonly int $maximumBytes = 8_388_608,
    ) {
        if ($allowedHosts === [] || $maximumBytes < 1024) {
            throw new \InvalidArgumentException('PDF policy requires hosts and a reasonable size limit.');
        }
    }

    public function accepts(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return false;
        }
        foreach ($this->allowedHosts as $host) {
            if (strcasecmp((string) $parts['host'], $host) === 0) {
                return true;
            }
        }
        return false;
    }

    public function maximumBytes(): int
    {
        return $this->maximumBytes;
    }
}
