<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Infrastructure;

use Pagou\Whmcs\Infrastructure\Whmcs\PrivateStorage;
use Pagou\Whmcs\Payment\Boleto\Contracts\PdfStorage;

/** Stores PDFs outside the web root and returns only opaque relative keys. */
final class PrivatePdfStorage implements PdfStorage
{
    public function __construct(private readonly PrivateStorage $storage)
    {
    }

    public function put(string $key, string $contents, string $mimeType): string
    {
        if ($mimeType !== 'application/pdf' || !str_starts_with($contents, '%PDF-')) {
            throw new \InvalidArgumentException('Only validated PDF content may enter boleto storage.');
        }
        $this->storage->put($key, $contents);
        return $key;
    }

    public function has(string $key): bool
    {
        try {
            $this->storage->read($key);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
