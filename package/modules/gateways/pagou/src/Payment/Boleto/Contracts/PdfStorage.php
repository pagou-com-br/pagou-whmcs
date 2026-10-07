<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Contracts;

interface PdfStorage
{
    /** Returns an opaque local key, never a remote URL. */
    public function put(string $key, string $contents, string $mimeType): string;

    public function has(string $key): bool;
}
