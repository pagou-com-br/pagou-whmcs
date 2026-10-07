<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Dto;

final class PixArtifacts
{
    public function __construct(
        public readonly ?string $copyPaste,
        public readonly ?string $qrCodeImage,
        public readonly ?string $qrCodeUrl,
    ) {
    }
}
