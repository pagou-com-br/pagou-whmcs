<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Domain;

/** Artifacts returned by one boleto charge. Pix is embedded in this same charge. */
final class BoletoArtifacts
{
    public function __construct(
        public readonly ?string $digitableLine = null,
        public readonly ?string $barcode = null,
        public readonly ?string $pixCopyPaste = null,
        public readonly ?string $pixQrCode = null,
        public readonly ?string $pdfUrl = null,
        public readonly ?string $localPdfKey = null,
    ) {
    }

    public function withLocalPdf(string $key): self
    {
        return new self($this->digitableLine, $this->barcode, $this->pixCopyPaste, $this->pixQrCode, $this->pdfUrl, $key);
    }

    public function isReady(): bool
    {
        return $this->digitableLine !== null && $this->barcode !== null;
    }
}
