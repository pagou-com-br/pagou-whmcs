<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Whmcs;

use InvalidArgumentException;
use RuntimeException;

final class WhmcsEmailSender
{
    public function __construct(private readonly WhmcsPorts $ports)
    {
    }

    /** @param array<string,mixed> $mergeFields */
    public function send(string $template, int $clientId, array $mergeFields = []): void
    {
        if ($template === '' || $clientId < 1) {
            throw new InvalidArgumentException('An email template and client identifier are required.');
        }
        $this->assertSafeMergeFields($mergeFields);

        if (!$this->ports->sendEmail($template, $clientId, $mergeFields)) {
            throw new RuntimeException('WHMCS could not queue the payment email.');
        }
    }

    /** @param array<string,mixed> $mergeFields */
    private function assertSafeMergeFields(array $mergeFields): void
    {
        foreach (array_keys($mergeFields) as $key) {
            if (preg_match('/(?:pan|card.?number|cvv|cvc|security.?code)/i', $key)) {
                throw new InvalidArgumentException('Sensitive card data must never be sent through email merge fields.');
            }
        }
    }
}
