<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Exception;

final class PixApiException extends \RuntimeException
{
    /** @param array<string, mixed> $response */
    public function __construct(string $message, public readonly int $status = 0, public readonly array $response = [])
    {
        parent::__construct($message, $status);
    }
}
